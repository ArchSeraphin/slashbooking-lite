<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Slash\Booking\Activator;
use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Persistence\BookingRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class PublicCancelControllerTest extends WP_UnitTestCase
{
    private DecisionTokenSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        do_action('rest_api_init');
        $this->signer = new DecisionTokenSigner((string) get_option('sb_decision_secret'));
    }

    public function test_get_renders_interstitial_and_does_not_cancel(): void
    {
        $b = $this->seedPending();
        $exp = time() + 3600;
        $sig = $this->signer->sign('cancel|' . $b->publicUid(), $exp);
        $request = new WP_REST_Request('GET', '/slashbooking/v1/cancel');
        $request->set_query_params(['uid' => $b->publicUid(), 'exp' => $exp, 'sig' => $sig]);
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        self::assertStringContainsString('<form', (string) $response->get_data());

        global $wpdb;
        $refreshed = (new BookingRepository($wpdb))->findByPublicUid($b->publicUid());
        self::assertSame(BookingStatus::PENDING, $refreshed->status(), 'GET must not cancel the booking');
    }

    public function test_post_cancels(): void
    {
        $b = $this->seedPending();
        $exp = time() + 3600;
        $sig = $this->signer->sign('cancel|' . $b->publicUid(), $exp);
        $request = new WP_REST_Request('POST', '/slashbooking/v1/cancel');
        $request->set_query_params(['uid' => $b->publicUid(), 'exp' => $exp, 'sig' => $sig]);
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        self::assertSame(['status' => 'cancelled'], $response->get_data());

        global $wpdb;
        $refreshed = (new BookingRepository($wpdb))->findByPublicUid($b->publicUid());
        self::assertSame(BookingStatus::CANCELLED, $refreshed->status());
    }

    private function seedPending(): Booking
    {
        global $wpdb;
        $repo = new BookingRepository($wpdb);
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'jean@test.fr',
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        $repo->save($b);
        return $b;
    }
}
