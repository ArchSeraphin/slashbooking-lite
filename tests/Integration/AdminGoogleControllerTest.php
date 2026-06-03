<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use Slash\Booking\Domain\BusyBlock;
use Slash\Booking\Persistence\BusyBlockRepository;
use Slash\Booking\Persistence\GoogleAccountRepository;

final class AdminGoogleControllerTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $httpStub = [];

    protected function setUp(): void
    {
        if (!function_exists('do_action')) {
            $this->markTestSkipped('Requires wp-phpunit.');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sb_google_accounts");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sb_sync_log");
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sb_busy_blocks");

        update_option('sb_decision_secret', str_repeat('a', 64), false);
        update_option('sb_google_client_id', 'cid');
        update_option('sb_google_client_secret', 'csecret');

        $this->httpStub = [];
        add_filter('pre_http_request', [$this, 'interceptHttp'], 10, 3);

        wp_set_current_user(1);
        $user = wp_get_current_user();
        $user->add_cap('slashbooking_manage');
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'interceptHttp'], 10);
    }

    public function interceptHttp(mixed $preempt, array $args, string $url): array
    {
        $this->httpStub[] = compact('args', 'url');
        if (str_contains($url, 'oauth2.googleapis.com/token')) {
            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'body'     => (string) wp_json_encode([
                    'access_token'  => 'access-XYZ',
                    'refresh_token' => 'refresh-XYZ',
                    'expires_in'    => 3600,
                    'scope'         => 'https://www.googleapis.com/auth/calendar.events',
                    'token_type'    => 'Bearer',
                ]),
                'headers'  => [],
                'cookies'  => [],
            ];
        }
        return ['response' => ['code' => 500, 'message' => 'KO'], 'body' => '', 'headers' => [], 'cookies' => []];
    }

    public function test_start_returns_auth_url_with_state(): void
    {
        do_action('rest_api_init');
        $req = new WP_REST_Request('POST', '/slashbooking/v1/admin/google/oauth/start');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));

        $res = rest_do_request($req);
        self::assertSame(200, $res->get_status());
        $data = $res->get_data();
        self::assertArrayHasKey('auth_url', $data);
        self::assertStringContainsString('accounts.google.com', (string) $data['auth_url']);
        self::assertStringContainsString('state=', (string) $data['auth_url']);
    }

    public function test_callback_exchanges_code_and_persists_account(): void
    {
        do_action('rest_api_init');
        global $wpdb;

        $state = (new \Slash\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);

        $req = new WP_REST_Request('GET', '/slashbooking/v1/admin/google/oauth/callback');
        $req->set_query_params(['code' => 'auth-CODE', 'state' => $state]);

        $res = rest_do_request($req);
        self::assertNotSame(403, $res->get_status());

        $repo = new GoogleAccountRepository($wpdb);
        $acct = $repo->findSingle();
        self::assertNotNull($acct);
        // Refresh token must be encrypted (not raw).
        self::assertNotSame('refresh-XYZ', $acct->refreshTokenEnc());
    }

    public function test_callback_rejects_invalid_state(): void
    {
        do_action('rest_api_init');
        $req = new WP_REST_Request('GET', '/slashbooking/v1/admin/google/oauth/callback');
        $req->set_query_params(['code' => 'auth-CODE', 'state' => 'garbage']);
        $res = rest_do_request($req);
        self::assertSame(403, $res->get_status());
    }

    public function test_status_returns_connected_after_callback(): void
    {
        do_action('rest_api_init');

        $state = (new \Slash\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);
        $cb = new WP_REST_Request('GET', '/slashbooking/v1/admin/google/oauth/callback');
        $cb->set_query_params(['code' => 'c', 'state' => $state]);
        rest_do_request($cb);

        $req = new WP_REST_Request('GET', '/slashbooking/v1/admin/google/status');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $res = rest_do_request($req);
        $data = $res->get_data();
        self::assertTrue($data['connected']);
        self::assertSame('primary', $data['calendar_id']);
    }

    public function test_set_calendar_change_purges_stale_busy_blocks_and_resets_sync(): void
    {
        do_action('rest_api_init');
        global $wpdb;

        // Connect an account (calendar defaults to 'primary').
        $state = (new \Slash\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);
        $cb = new WP_REST_Request('GET', '/slashbooking/v1/admin/google/oauth/callback');
        $cb->set_query_params(['code' => 'c', 'state' => $state]);
        rest_do_request($cb);

        $repo = new GoogleAccountRepository($wpdb);
        $acct = $repo->findSingle();
        self::assertNotNull($acct);
        $acctId = (int) $acct->id();

        // Simulate prior sync state: a sync token + a busy block from the old calendar.
        $acct->updateSyncToken('stale-token');
        $repo->save($acct);

        $busy = new BusyBlockRepository($wpdb);
        $busy->upsertFromGoogle(BusyBlock::fromGoogleEvent(
            $acctId,
            'old_cal_event',
            new DateTimeImmutable('2026-06-10 09:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-10 10:00:00', new DateTimeZone('UTC')),
            'Old calendar event',
        ));
        self::assertNotNull($busy->findBySourceId($acctId, 'old_cal_event'));

        // Switch to a different calendar.
        $req = new WP_REST_Request('POST', '/slashbooking/v1/admin/google/calendar');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $req->set_param('calendar_id', 'work@group.calendar.google.com');
        $res = rest_do_request($req);
        self::assertSame(200, $res->get_status());

        // Stale busy block is gone, sync token reset for a clean full resync.
        self::assertNull($busy->findBySourceId($acctId, 'old_cal_event'));
        $reloaded = $repo->findSingle();
        self::assertNotNull($reloaded);
        self::assertSame('work@group.calendar.google.com', $reloaded->calendarId());
        self::assertNull($reloaded->syncToken());
    }

    public function test_set_calendar_same_value_keeps_busy_blocks(): void
    {
        do_action('rest_api_init');
        global $wpdb;

        $state = (new \Slash\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);
        $cb = new WP_REST_Request('GET', '/slashbooking/v1/admin/google/oauth/callback');
        $cb->set_query_params(['code' => 'c', 'state' => $state]);
        rest_do_request($cb);

        $repo = new GoogleAccountRepository($wpdb);
        $acct = $repo->findSingle();
        self::assertNotNull($acct);
        $acctId = (int) $acct->id();
        $acct->updateSyncToken('keep-token');
        $repo->save($acct);

        $busy = new BusyBlockRepository($wpdb);
        $busy->upsertFromGoogle(BusyBlock::fromGoogleEvent(
            $acctId,
            'current_event',
            new DateTimeImmutable('2026-06-11 09:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-11 10:00:00', new DateTimeZone('UTC')),
            'Current calendar event',
        ));

        // Re-select the SAME calendar ('primary') — must be a no-op.
        $req = new WP_REST_Request('POST', '/slashbooking/v1/admin/google/calendar');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $req->set_param('calendar_id', 'primary');
        $res = rest_do_request($req);
        self::assertSame(200, $res->get_status());

        self::assertNotNull($busy->findBySourceId($acctId, 'current_event'));
        $reloaded = $repo->findSingle();
        self::assertNotNull($reloaded);
        self::assertSame('keep-token', $reloaded->syncToken());
    }

    public function test_disconnect_removes_account(): void
    {
        do_action('rest_api_init');

        $state = (new \Slash\Booking\Google\OAuthState(str_repeat('a', 64)))->issue(1);
        $cb = new WP_REST_Request('GET', '/slashbooking/v1/admin/google/oauth/callback');
        $cb->set_query_params(['code' => 'c', 'state' => $state]);
        rest_do_request($cb);

        $req = new WP_REST_Request('POST', '/slashbooking/v1/admin/google/disconnect');
        $req->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $res = rest_do_request($req);
        self::assertSame(200, $res->get_status());

        global $wpdb;
        $repo = new GoogleAccountRepository($wpdb);
        self::assertNull($repo->findSingle());
    }
}
