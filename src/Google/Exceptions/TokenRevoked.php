<?php
declare(strict_types=1);

namespace Slash\Booking\Google\Exceptions;

/**
 * Google returned invalid_grant (refresh_token permanently revoked). The caller
 * MUST mark the account "reconnection required" and MUST keep the booking data.
 */
final class TokenRevoked extends \RuntimeException
{
}
