<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\PublicFront;

use PHPUnit\Framework\TestCase;
use Slash\Booking\PublicFront\CacheCompat;

final class CacheCompatTest extends TestCase
{
    public function test_applies_no_cache_when_page_has_widget(): void
    {
        $applied = 0;
        $cc = new CacheCompat(
            pageHasWidget: static fn (): bool => true,
            applyNoCache: static function () use (&$applied): void {
                $applied++;
            },
        );

        $cc->maybeDisableCache();

        self::assertSame(1, $applied);
    }

    public function test_does_nothing_when_page_has_no_widget(): void
    {
        $applied = 0;
        $cc = new CacheCompat(
            pageHasWidget: static fn (): bool => false,
            applyNoCache: static function () use (&$applied): void {
                $applied++;
            },
        );

        $cc->maybeDisableCache();

        self::assertSame(0, $applied);
    }

    public function test_disable_cache_is_idempotent(): void
    {
        $applied = 0;
        $cc = new CacheCompat(
            pageHasWidget: static fn (): bool => true,
            applyNoCache: static function () use (&$applied): void {
                $applied++;
            },
        );

        // Détection au hook `wp` PUIS fallback au rendu du shortcode :
        // l'application ne doit se faire qu'une seule fois.
        $cc->maybeDisableCache();
        $cc->disableCache();
        $cc->disableCache();

        self::assertSame(1, $applied);
    }

    public function test_render_fallback_applies_even_without_detection(): void
    {
        $applied = 0;
        $cc = new CacheCompat(
            // Page builder : le shortcode n'est pas dans post_content,
            // la détection au hook `wp` ne voit rien…
            pageHasWidget: static fn (): bool => false,
            applyNoCache: static function () use (&$applied): void {
                $applied++;
            },
        );

        $cc->maybeDisableCache();
        // …mais le rendu du shortcode déclenche le fallback.
        $cc->disableCache();

        self::assertSame(1, $applied);
    }
}
