<?php
declare(strict_types=1);

namespace Slash\Booking\PublicFront;

/**
 * Empêche les plugins de cache de figer la page qui contient le widget de
 * réservation.
 *
 * Une page mise en cache servait un nonce REST périmé (wp_create_nonce vit
 * 12-24 h) : le core WP rejette alors TOUTE requête portant un X-WP-Nonce
 * invalide (403 rest_cookie_invalid_nonce), même sur nos routes publiques —
 * le widget perdait ses services. Le nonce a été retiré du widget (v1.2.2),
 * mais on exclut aussi la page du cache en défense en profondeur : les
 * minifications HTML/JS agressives de certains plugins de cache cassent le
 * markup, et l'exclusion garantit un formulaire toujours frais.
 *
 * Mécanismes, du plus universel au plus spécifique :
 *  - constante DONOTCACHEPAGE : honorée par WP Fastest Cache, WP Rocket,
 *    W3 Total Cache, WP Super Cache, LiteSpeed Cache, Cache Enabler,
 *    WP-Optimize, Hummingbird…
 *  - nocache_headers() : Cache-Control no-cache → navigateur, CDN, Varnish,
 *    caches serveur (SiteGround, Cloudflare APO…)
 *  - action litespeed_control_set_nocache : API publique LiteSpeed.
 *
 * Deux points d'entrée :
 *  - hook `wp` (tôt, avant l'envoi des en-têtes) quand le shortcode est
 *    détectable dans post_content ;
 *  - fallback depuis Shortcode::render() pour les page builders qui stockent
 *    le shortcode hors post_content (Beaver Builder, widgets…). À ce stade la
 *    constante reste efficace : les plugins de cache la vérifient à la fin du
 *    buffer de sortie.
 */
final class CacheCompat
{
    public const SHORTCODE_TAG = 'slashbooking';

    private bool $applied = false;

    /**
     * @param \Closure(): bool $pageHasWidget la requête courante rend-elle le widget ?
     * @param \Closure(): void $applyNoCache  effets de bord no-cache (constante, en-têtes, signaux)
     */
    public function __construct(
        private readonly \Closure $pageHasWidget,
        private readonly \Closure $applyNoCache,
    ) {
    }

    /** Câblage WordPress réel (les closures injectées servent aux tests). */
    public static function forWordPress(): self
    {
        return new self(
            pageHasWidget: static function (): bool {
                if (!is_singular()) {
                    return false;
                }
                $post = get_post();
                return $post !== null && has_shortcode((string) $post->post_content, self::SHORTCODE_TAG);
            },
            applyNoCache: static function (): void {
                if (!defined('DONOTCACHEPAGE')) {
                    define('DONOTCACHEPAGE', true);
                }
                if (!headers_sent()) {
                    nocache_headers();
                }
                // LiteSpeed Cache n'honore pas DONOTCACHEPAGE dans toutes ses
                // versions — son API dédiée est ce do_action (no-op sans LiteSpeed).
                do_action('litespeed_control_set_nocache', 'slashbooking: booking widget on page');
            },
        );
    }

    public function register(): void
    {
        // `wp` : la requête principale est résolue (is_singular/get_post fiables)
        // et les en-têtes HTTP ne sont pas encore envoyés.
        add_action('wp', [$this, 'maybeDisableCache']);
    }

    public function maybeDisableCache(): void
    {
        if (($this->pageHasWidget)()) {
            $this->disableCache();
        }
    }

    /** Idempotent — appelé par la détection ET par le fallback du shortcode. */
    public function disableCache(): void
    {
        if ($this->applied) {
            return;
        }
        $this->applied = true;
        ($this->applyNoCache)();
    }
}
