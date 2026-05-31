<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use DateTimeImmutable;
use DateTimeZone;
use Google\Client as GoogleClient;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Google\Exceptions\BrokerUnavailable;
use Slash\Booking\Google\Exceptions\TokenRevoked;
use Slash\Booking\Persistence\GoogleAccountRepository;

final class GoogleClientBuilder
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly';

    public function __construct(
        private readonly Encryption $encryption,
        private readonly GoogleAccountRepository $accounts,
        private readonly BrokerGateway $broker,
    ) {
    }

    public function buildGateway(GoogleAccount $account): CalendarGateway
    {
        // No client_id / client_secret: the plugin ships no Google credentials.
        // Calendar API calls are authorized by the Bearer access token only.
        $client = new GoogleClient();
        $client->addScope(self::SCOPE);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($account->accessTokenExpired($now->modify('+30 seconds'))) {
            $tokens = $this->refreshAccessToken($account);
            $client->setAccessToken([
                'access_token' => $tokens['access_token'],
                'expires_in'   => $tokens['expires_in'],
                'created'      => $now->getTimestamp(),
            ]);
        } else {
            $client->setAccessToken([
                'access_token' => $this->encryption->decrypt($account->accessTokenEnc()),
                'expires_in'   => max(0, $account->expiresAt()->getTimestamp() - $now->getTimestamp()),
                'created'      => $now->getTimestamp(),
            ]);
        }

        return new GoogleApiCalendarGateway($client);
    }

    /**
     * Refresh the access token through the broker, rotate + persist it.
     *
     * @return array{access_token:string, expires_in:int}
     * @throws BrokerUnavailable broker down (retryable) — tokens are kept intact
     * @throws TokenRevoked      refresh token revoked — account flagged, data kept
     */
    public function refreshAccessToken(GoogleAccount $account): array
    {
        $refresh = $this->encryption->decrypt($account->refreshTokenEnc());

        try {
            $tokens = $this->broker->refresh($refresh);
        } catch (BrokerUnavailable $e) {
            // Retryable: do NOT clear tokens, do NOT persist. Caller will retry.
            throw $e;
        } catch (TokenRevoked $e) {
            $account->markReconnectRequired();
            $this->accounts->save($account);
            throw $e;
        }

        $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . $tokens['expires_in'] . ' seconds');
        $account->rotateAccessToken($this->encryption->encrypt($tokens['access_token']), $expiresAt);
        $this->accounts->save($account);

        return $tokens;
    }
}
