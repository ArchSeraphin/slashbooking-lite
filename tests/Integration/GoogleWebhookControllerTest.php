<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Http\GoogleWebhookController;
use Slash\Booking\Persistence\GoogleAccountRepository;
use WP_REST_Request;

final class GoogleWebhookControllerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WP_REST_Request')) {
            self::markTestSkipped('WP REST not available.');
        }
    }

    protected function setUp(): void
    {
        // The webhook now dedups pulls via a transient lock keyed on the channel
        // id; clear it before each test so enqueue expectations stay deterministic.
        if (function_exists('delete_transient')) {
            delete_transient('sb_webhook_pull_' . md5('ch_known'));
        }
    }

    private function freshAccount(string $watchExpiry = '+1 day'): GoogleAccount
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DELETE FROM {$wpdb->prefix}sb_google_accounts");

        $repo = new GoogleAccountRepository($wpdb);
        $a = GoogleAccount::connect(
            'l',
            'primary',
            'r',
            'a',
            new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
        $a->attachWatch('ch_known', 'res_known', 'sec_known', new DateTimeImmutable($watchExpiry, new DateTimeZone('UTC')));
        $repo->save($a);
        return $a;
    }

    public function test_rejects_wrong_token(): void
    {
        $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'wrong');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-State', 'exists');

        $resp = $ctrl->handle($req);
        self::assertSame(401, $resp->get_status());
        self::assertSame([], $enqueued);
    }

    public function test_accepts_valid_token_and_enqueues_pull(): void
    {
        $account = $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'sec_known');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-Id', 'res_known');
        $req->set_header('X-Goog-Resource-State', 'exists');

        $resp = $ctrl->handle($req);
        self::assertSame(200, $resp->get_status());
        self::assertSame([(int) $account->id()], $enqueued);
    }

    public function test_sync_state_ack_no_pull(): void
    {
        $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'sec_known');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-Id', 'res_known');
        $req->set_header('X-Goog-Resource-State', 'sync');

        $resp = $ctrl->handle($req);
        self::assertSame(200, $resp->get_status());
        self::assertSame([], $enqueued);
    }

    public function test_expired_watch_is_acknowledged_but_not_enqueued(): void
    {
        $this->freshAccount('-1 hour'); // watch already expired
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'sec_known');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-Id', 'res_known');
        $req->set_header('X-Goog-Resource-State', 'exists');

        $resp = $ctrl->handle($req);
        self::assertSame(200, $resp->get_status());
        self::assertSame([], $enqueued, 'expired channel must not enqueue a pull');
    }

    public function test_wrong_resource_id_is_acknowledged_but_not_enqueued(): void
    {
        $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'sec_known');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-Id', 'WRONG-RES');
        $req->set_header('X-Goog-Resource-State', 'exists');

        $resp = $ctrl->handle($req);
        self::assertSame(200, $resp->get_status());
        self::assertSame([], $enqueued, 'mismatched resource id must not enqueue a pull');
    }

    public function test_valid_active_webhook_enqueues_once_then_dedups(): void
    {
        $account = $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $build = static function (): WP_REST_Request {
            $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
            $req->set_header('X-Goog-Channel-Token', 'sec_known');
            $req->set_header('X-Goog-Channel-Id', 'ch_known');
            $req->set_header('X-Goog-Resource-Id', 'res_known');
            $req->set_header('X-Goog-Resource-State', 'exists');
            return $req;
        };

        $first  = $ctrl->handle($build());
        $second = $ctrl->handle($build());

        self::assertSame(200, $first->get_status());
        self::assertSame(200, $second->get_status());
        self::assertSame([(int) $account->id()], $enqueued, 'second webhook within dedup window must not re-enqueue');
    }
}
