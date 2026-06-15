<?php
declare(strict_types=1);

namespace Slash\Booking;

final class Plugin
{
    public const VERSION = '1.0.5';
    public const TEXT_DOMAIN = 'slashbooking';
    public const DB_VERSION = 2;
    public const REST_NAMESPACE = 'slashbooking/v1';

    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    private string $pluginFile;

    private function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }

    public static function boot(string $pluginFile): self
    {
        if (self::$instance === null) {
            self::$instance = new self($pluginFile);
            self::$instance->register();
        }
        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Plugin not booted');
        }
        return self::$instance;
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    public function pluginFile(): string
    {
        return $this->pluginFile;
    }

    public function pluginDir(): string
    {
        return \dirname($this->pluginFile);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param T $instance
     */
    public function set(string $id, object $instance): void
    {
        $this->services[$id] = $instance;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered to the browser
            throw new \RuntimeException("Service not registered: {$id}");
        }
        /** @var T */
        return $this->services[$id];
    }

    private function register(): void
    {
        // Plugin::register() runs at plugin sandbox scrape, BEFORE the
        // register_activation_hook fires. Without this idempotent seed, a fresh
        // install fatals on `new DecisionTokenSigner('')` further down.
        Activator::ensureDecisionSecret();

        // Load the bundled translation for the current locale. The plugin's
        // source strings are English; a complete French catalog ships in
        // /languages. We load the .mo directly with load_textdomain() — rather
        // than load_plugin_textdomain() — so the bundled catalog is available
        // immediately, even before a translate.wordpress.org language pack
        // exists. Hooked on `init` per WP 6.7 just-in-time-loading guidance.
        add_action('init', function (): void {
            $locale = determine_locale();
            $mofile = $this->pluginDir() . '/languages/slashbooking-' . $locale . '.mo';
            if (is_readable($mofile)) {
                load_textdomain('slashbooking', $mofile);
            }
        });

        // Propagate capability changes to existing installs that were activated
        // under an older revision (e.g. before editor role got plugin access).
        Admin\Capabilities::syncOnUpgrade();

        $router = new Http\RestRouter();
        $router->register();
        $this->set(Http\RestRouter::class, $router);

        global $wpdb;

        // Run pending schema migrations on every boot, not just on activation:
        // plugin UPDATES do NOT fire the activation hook, so without this a client
        // who updates never gets new columns. Migrator self-gates on the
        // slashbooking_db_version option, so this is a cheap no-op once the schema is current.
        (new Persistence\Migrator($wpdb))->migrate();

        $services = new Persistence\ServiceRepository($wpdb);

        // Pages contenant le widget : exclues du cache de page (nonce périmé,
        // minification agressive — voir PublicFront\CacheCompat).
        $cacheCompat = PublicFront\CacheCompat::forWordPress();
        $cacheCompat->register();

        $shortcode = new PublicFront\Shortcode($services, $cacheCompat);
        $shortcode->register();

        $bookings = new Persistence\BookingRepository($wpdb);

        // WP privacy data exporters / erasers
        $privacyExporter = new Privacy\BookingExporter(
            findByEmail: fn (string $email) => $bookings->findByCustomerEmail($email),
        );
        add_filter('wp_privacy_personal_data_exporters', static function (array $exporters) use ($privacyExporter): array {
            $exporters['slashbooking'] = [
                'exporter_friendly_name' => __('SlashBooking', 'slashbooking'),
                'callback'               => static fn (string $email, int $page = 1) => $privacyExporter->export($email, $page),
            ];
            return $exporters;
        });

        $privacyEraser = new Privacy\BookingEraser(
            anonymizeByEmail: fn (string $email) => $bookings->anonymizeByEmail($email),
        );
        add_filter('wp_privacy_personal_data_erasers', static function (array $erasers) use ($privacyEraser): array {
            $erasers['slashbooking'] = [
                'eraser_friendly_name' => __('SlashBooking', 'slashbooking'),
                'callback'             => static fn (string $email, int $page = 1) => $privacyEraser->erase($email, $page),
            ];
            return $erasers;
        });

        // Custom monthly cron interval (used by the retention purger). Must also
        // be present at runtime, not just at activation.
        add_filter('cron_schedules', static function (array $s): array {
            if (!isset($s['slashbooking_monthly'])) {
                $s['slashbooking_monthly'] = [
                    'interval' => 2_592_000,
                    'display'  => 'Once every 30 days (SlashBooking)',
                ];
            }
            return $s;
        });

        Privacy\BookingRetentionPurger::register();

        $signer = new Booking\DecisionTokenSigner((string) get_option('slashbooking_decision_secret'));
        // Lazy URL resolver — rest_url() requires $wp_rewrite which is not yet
        // initialized at plugin file load time. The closure fires later, when
        // BookingNotifier callbacks actually need to build a URL.
        $urls = new Http\UrlBuilder($signer, fn (): string => rest_url(self::REST_NAMESPACE));

        $dispatcher = new Notifications\MailDispatcher(
            new Persistence\MailTemplateRepository($wpdb),
            new Notifications\TemplateRenderer(new Notifications\TagRegistry()),
            new Notifications\TextBodyGenerator(),
            new Notifications\IcsBuilder(),
        );
        $this->set(Notifications\MailDispatcher::class, $dispatcher);

        (new Notifications\BookingNotifier(
            $services,
            $bookings,
            $dispatcher,
            $urls,
        ))->register();

        (new Admin\AdminMenu())->register();
        (new Admin\Assets($this))->register();
        (new Admin\DashboardWidget($bookings, $services))->register();
    }
}
