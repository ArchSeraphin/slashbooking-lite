<?php
declare(strict_types=1);

namespace Slash\Booking\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

// NB : readonly par propriété, pas `readonly class` — syntaxe PHP 8.2 alors que
// le plugin annonce "Requires PHP: 8.1" (fatal au parse sur les hôtes 8.1).
final class TimeSlot
{
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {
        if ($start->getOffset() !== 0 || $end->getOffset() !== 0) {
            throw new InvalidArgumentException('TimeSlot dates must be UTC.');
        }
        if ($start >= $end) {
            throw new InvalidArgumentException('TimeSlot start must be before end.');
        }
    }

    public function durationMinutes(): int
    {
        return (int) (($this->end->getTimestamp() - $this->start->getTimestamp()) / 60);
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function expand(int $beforeMinutes, int $afterMinutes): self
    {
        return new self(
            $this->start->modify(sprintf('-%d minutes', $beforeMinutes)),
            $this->end->modify(sprintf('+%d minutes', $afterMinutes)),
        );
    }

    /**
     * @return array{start:string,end:string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->format(DATE_ATOM),
            'end'   => $this->end->format(DATE_ATOM),
        ];
    }
}
