<?php
declare(strict_types=1);

namespace Slash\Booking\Updates;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Wires the Plugin Update Checker library against a public GitHub repository
 * hosting tagged releases. WordPress admin then sees update notifications and
 * "Update now" buttons exactly like for plugins hosted on wp.org.
 *
 * Release flow: pushing a tag `v1.2.3` triggers a GitHub Actions workflow that
 * builds the distribution ZIP and attaches it as a release asset. PUC polls the
 * /releases/latest endpoint, downloads the asset, and lets WP install it.
 */
final class UpdateChecker
{
    public const REPO_URL = 'https://github.com/ArchSeraphin/slashbooking/';
    public const PLUGIN_SLUG = 'slashbooking';
    public const RELEASE_BRANCH = 'main';

    public static function bootstrap(string $pluginFile): void
    {
        // CRITICAL: always require the entrypoint, even if PucFactory::class is
        // already known to the composer classmap (classmap-authoritative scans
        // vendor/ at build time so PucFactory.php is autoloadable directly).
        // The class registry that powers PucFactory::buildUpdateChecker() lives
        // in load-v5p6.php which is *only* sourced via plugin-update-checker.php.
        // Skipping this require leaves the registry empty → fatal
        // "PUC does not support updates for plugins hosted on GitHub".
        $loader = \dirname($pluginFile) . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
        if (!is_readable($loader)) {
            return;
        }
        require_once $loader;

        $checker = PucFactory::buildUpdateChecker(
            self::REPO_URL,
            $pluginFile,
            self::PLUGIN_SLUG,
        );

        // buildUpdateChecker() est typé en union Plugin|Theme|Vcs\BaseChecker
        // qui ne portent pas tous setBranch()/getVcsApi(). On duck-type plutôt
        // que d'instanceof-er la classe concrète : son namespace contient la
        // version PUC (v5p6) et changerait silencieusement à la prochaine bump
        // de la lib (même piège que le dispatch runtime déjà documenté plus haut).
        if (method_exists($checker, 'setBranch')) {
            $checker->setBranch(self::RELEASE_BRANCH);
        }

        $vcs = method_exists($checker, 'getVcsApi') ? $checker->getVcsApi() : null;
        if (is_object($vcs) && method_exists($vcs, 'enableReleaseAssets')) {
            // GitHub release asset (the .zip uploaded by the Actions workflow)
            // is the installable artifact — without this, PUC tries to install
            // a source-tree tarball which has no built /vendor.
            $vcs->enableReleaseAssets('/slashbooking-.*\.zip$/i');
        }

        // Defensive trim. PUC reads the Version header from the remote
        // slashbooking.php and only trims via _cleanup_header_comment() if
        // function_exists() — which can return false in some environments
        // (cron contexts, exotic WP builds). When that happens, the version
        // ends up with 11+ leading spaces (from header alignment padding) and
        // version_compare() in the update injection fails silently. Trimming
        // here closes the gap regardless of context.
        $trimVersion = static function ($result) {
            if (is_object($result) && isset($result->version) && is_string($result->version)) {
                $result->version = trim($result->version);
            }
            return $result;
        };
        add_filter('puc_request_info_result-' . self::PLUGIN_SLUG, $trimVersion);
        add_filter('puc_request_update_result-' . self::PLUGIN_SLUG, $trimVersion);
    }
}
