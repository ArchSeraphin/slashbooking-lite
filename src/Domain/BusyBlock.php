<?php
declare(strict_types=1);

namespace Slash\Booking\Domain;

use DateTimeImmutable;
use DateTimeZone;

// NB : readonly par propriété, pas `readonly class` — syntaxe PHP 8.2 alors que
// le plugin annonce "Requires PHP: 8.1" (fatal au parse sur les hôtes 8.1).
final class BusyBlock
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $source,
        public readonly string $sourceId,
        public readonly ?int $googleAccountId,
        public readonly TimeSlot $slot,
        public readonly string $summary,
        public readonly ?DateTimeImmutable $lastSyncedAt = null,
    ) {
    }

    public static function fromGoogleEvent(
        int $googleAccountId,
        string $eventId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $summary,
        ?DateTimeImmutable $syncedAt = null,
    ): self {
        $utc = new DateTimeZone('UTC');
        return new self(
            id: null,
            source: 'google',
            sourceId: $eventId,
            googleAccountId: $googleAccountId,
            slot: new TimeSlot($start->setTimezone($utc), $end->setTimezone($utc)),
            summary: $summary,
            lastSyncedAt: $syncedAt?->setTimezone($utc) ?? new DateTimeImmutable('now', $utc),
        );
    }
}
