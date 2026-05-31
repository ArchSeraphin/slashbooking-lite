<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

/**
 * Seam over BrokerClient so controllers/builders can be unit-tested with a fake.
 * BrokerClient (final) implements this; FakeBrokerClient implements it for tests.
 */
interface BrokerGateway
{
    public function startUrl(string $returnUrl, string $n): string;

    /**
     * @return array{refresh_token:string, access_token:string, expires_in:int, scope:string, email:string, calendar_id:string}
     */
    public function claim(string $claimCode): array;

    /**
     * @return array{access_token:string, expires_in:int}
     */
    public function refresh(string $refreshToken): array;

    /**
     * @return array{valid: bool, plan: ?string, expires: ?string}
     */
    public function validateLicense(string $siteUrl): array;
}
