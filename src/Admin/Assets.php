<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

use Slash\Booking\Plugin;

final class Assets
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'toplevel_page_slashbooking') {
            return;
        }
        $dir = $this->plugin->pluginDir();
        $url = plugin_dir_url($this->plugin->pluginFile());
        // wp-scripts derives bundle filename from the entry file:
        // src/Admin/react-app/src/index.jsx  →  assets/dist/index.jsx.{js,css,asset.php}
        $assetFile = $dir . '/assets/dist/index.jsx.asset.php';
        if (!is_file($assetFile)) {
            return;
        }
        /** @var array{dependencies:array<string>, version:string} $asset */
        $asset = require $assetFile;

        wp_enqueue_script(
            'slashbooking-admin',
            $url . 'assets/dist/index.jsx.js',
            $asset['dependencies'],
            $asset['version'],
            true,
        );

        wp_enqueue_style(
            'slashbooking-admin',
            $url . 'assets/dist/index.jsx.css',
            ['wp-components'],
            $asset['version'],
        );

        wp_localize_script('slashbooking-admin', 'SlashBooking', [
            'restUrl' => esc_url_raw(rest_url(Plugin::REST_NAMESPACE)),
            'nonce'   => wp_create_nonce('wp_rest'),
            'version' => Plugin::VERSION,
        ]);

        // Admin SPA translations. Source strings are English; the bundled
        // catalog (languages/slashbooking-{locale}.json) is injected for the
        // current locale. wp_set_script_translations() additionally wires up
        // WordPress.org JS language packs once they are generated.
        wp_set_script_translations('slashbooking-admin', 'slashbooking', $dir . '/languages');

        $locale = determine_locale();
        foreach ([$locale, substr($locale, 0, 2)] as $lc) {
            $jsonFile = $dir . '/languages/slashbooking-' . $lc . '.json';
            if (!is_file($jsonFile)) {
                continue;
            }
            $data = wp_json_file_decode($jsonFile, ['associative' => true]);
            if (is_array($data)) {
                wp_add_inline_script(
                    'slashbooking-admin',
                    'wp.i18n.setLocaleData(' . wp_json_encode($data) . ', "slashbooking");',
                    'before',
                );
            }
            break;
        }
    }
}
