<?php
declare(strict_types=1);

namespace Slash\Booking\PublicFront;

use Slash\Booking\Persistence\ServiceRepository;
use Slash\Booking\Plugin;

final class Shortcode
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly CacheCompat $cacheCompat,
    ) {
    }

    public function register(): void
    {
        add_shortcode(CacheCompat::SHORTCODE_TAG, [$this, 'render']);
    }

    /**
     * @param array<string, string>|string $attrs
     */
    public function render($attrs): string
    {
        $attrs = is_array($attrs) ? $attrs : [];

        // Fallback no-cache : couvre les page builders qui rendent le shortcode
        // hors post_content (la détection au hook `wp` ne l'a pas vu). Idempotent.
        $this->cacheCompat->disableCache();

        // The shortcode supports three forms:
        //   [slashbooking]                   -> user picks service in the widget (all active services)
        //   [slashbooking service="pv"]      -> service forced (no picker step)
        //   [slashbooking service="pv,irve"] -> picker filtered to a whitelist
        $rawService = isset($attrs['service']) ? sanitize_text_field((string) $attrs['service']) : '';
        $slugs = array_values(array_filter(array_map(
            static fn (string $s): string => preg_replace('/[^a-z0-9_\-]/i', '', trim($s)) ?? '',
            $rawService === '' ? [] : explode(',', $rawService)
        )));

        // If a single slug is provided, validate it. Any other case is left to the widget.
        if (count($slugs) === 1) {
            $svc = $this->services->findBySlug($slugs[0]);
            if ($svc === null || !$svc->isActive()) {
                return '<div class="sb-error">' . esc_html__('Service inconnu', 'slashbooking') . '</div>';
            }
            $serviceAttr = $svc->slug;
        } else {
            // Empty (= all services) or whitelist (= "pv,irve") — widget will fetch /services to render the picker.
            $serviceAttr = implode(',', $slugs);
        }

        // Enqueue assets directly here — WP queues them and prints in footer.
        $this->enqueueAssets();

        // Brand-color overrides are attached to the enqueued public stylesheet
        // as an inline style (no <style> tag is emitted in the shortcode output).
        $colorCss = $this->colorOverrideCss();
        if ($colorCss !== '') {
            wp_add_inline_style('slashbooking-public', $colorCss);
        }

        return sprintf(
            '<div class="sb-widget" data-sb-service="%s" data-sb-rest="%s"></div>',
            esc_attr($serviceAttr),
            esc_url(rest_url(Plugin::REST_NAMESPACE . '/')),
        );
    }

    /**
     * Build a CSS rule (no <style> tag) that overrides the booking widget's
     * --sb-c-primary / --sb-c-accent CSS custom properties when an admin
     * has set custom brand colors. Returned as bare CSS so it can be attached
     * via wp_add_inline_style(). Empty string when no override is configured.
     */
    private function colorOverrideCss(): string
    {
        $primary = (string) get_option('slashbooking_form_primary_color', '');
        $accent  = (string) get_option('slashbooking_form_accent_color', '');
        $rules   = [];
        if ($primary !== '') {
            $primary = sanitize_hex_color($primary) ?? '';
        }
        if ($accent !== '') {
            $accent = sanitize_hex_color($accent) ?? '';
        }
        if ($primary !== '') {
            $rules[] = '--sb-c-primary: ' . $primary;
            // Derive a hover shade by darkening the chosen hex.
            $rules[] = '--sb-c-primary-hover: ' . $this->darken($primary, 0.12);
        }
        if ($accent !== '') {
            $rules[] = '--sb-c-accent: ' . $accent;
        }
        if ($rules === []) {
            return '';
        }
        return '.sb-widget{' . implode(';', $rules) . '}';
    }

    /**
     * Returns a darker hex shade of the input color (#rrggbb) by multiplying
     * each channel by (1 - $amount). Used to derive a button-hover variant.
     */
    private function darken(string $hex, float $amount): string
    {
        if (!preg_match('/^#([0-9a-f]{6})$/i', $hex, $m)) {
            return $hex;
        }
        $rgb = array_map(static fn (string $h): int => (int) hexdec($h), str_split($m[1], 2));
        $rgb = array_map(static fn (int $c): int => max(0, (int) round($c * (1 - $amount))), $rgb);
        return sprintf('#%02x%02x%02x', ...$rgb);
    }

    private function enqueueAssets(): void
    {
        $pluginUrl = plugin_dir_url(Plugin::instance()->pluginFile());

        wp_enqueue_style(
            'slashbooking-public',
            $pluginUrl . 'src/PublicFront/assets/booking.css',
            [],
            Plugin::VERSION
        );

        $turnstileKey = (string) get_option('slashbooking_turnstile_site_key', '');
        $deps         = [];

        if ($turnstileKey !== '') {
            // Cloudflare Turnstile API — explicit render lets us insert the widget
            // after the booking form is built by booking.js. This is the opt-in
            // anti-bot service documented in readme.txt ("External services"); the
            // widget script can only be served from Cloudflare's CDN, never bundled.
            // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- Cloudflare Turnstile (CAPTCHA service) must load its widget from challenges.cloudflare.com; opt-in and disclosed in the readme.
            wp_enqueue_script(
                'slashbooking-turnstile',
                'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
                [],
                null,
                true
            );
            $deps[] = 'slashbooking-turnstile';
        }

        wp_enqueue_script(
            'slashbooking-public',
            $pluginUrl . 'src/PublicFront/assets/booking.js',
            $deps,
            Plugin::VERSION,
            true
        );

        $legalId  = (int) get_option('slashbooking_legal_page_id', 0);
        $legalUrl = $legalId > 0 ? (string) get_permalink($legalId) : '';

        // NB : pas de nonce REST ici. Les routes publiques du widget sont en
        // permission_callback __return_true (protection réelle = Turnstile +
        // honeypot + rate-limiting) et un nonce figé par un cache de page
        // expirait au bout de 12-24 h → le core WP renvoyait 403
        // rest_cookie_invalid_nonce sur tous les appels → plus de services.
        wp_localize_script('slashbooking-public', 'SlashBooking', [
            'locale'          => get_locale(),
            'legalUrl'        => $legalUrl,
            'disclaimer'      => (string) get_option('slashbooking_form_disclaimer', ''),
            'turnstileSiteKey'=> $turnstileKey,
        ]);
    }
}
