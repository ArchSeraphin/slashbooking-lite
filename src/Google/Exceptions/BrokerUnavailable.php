<?php
declare(strict_types=1);

namespace Slash\Booking\Google\Exceptions;

/**
 * Broker is unreachable (network failure or 5xx). Retryable: the caller MUST
 * keep the Google account connected and MUST NOT delete tokens.
 */
final class BrokerUnavailable extends \RuntimeException
{
}
