<?php
declare(strict_types=1);

namespace Slash\Booking\Privacy;

use Closure;
use Slash\Booking\Domain\Booking;

final class BookingExporter
{
    /**
     * @param Closure(string): list<Booking> $findByEmail
     */
    public function __construct(private readonly Closure $findByEmail)
    {
    }

    /**
     * @return array{data: list<array{group_id:string, group_label:string, item_id:string, data:list<array{name:string, value:string}>}>, done: bool}
     */
    public function export(string $email, int $page): array
    {
        $bookings = ($this->findByEmail)($email);
        $data = [];
        foreach ($bookings as $b) {
            $data[] = [
                'group_id'    => 'slashbooking',
                'group_label' => __('SlashBooking bookings', 'slashbooking'),
                'item_id'     => (string) ($b->id() ?? 0),
                'data'        => [
                    ['name' => __('Name', 'slashbooking'),     'value' => $b->customerName()],
                    ['name' => __('Email', 'slashbooking'),  'value' => $b->customerEmail()],
                    ['name' => __('Phone', 'slashbooking'), 'value' => $b->customerPhone()],
                    ['name' => __('Address', 'slashbooking'), 'value' => $b->customerAddress()],
                    ['name' => __('Notes', 'slashbooking'),   'value' => $b->notes()],
                    ['name' => __('Status', 'slashbooking'),  'value' => $b->status()->value],
                    ['name' => __('Appointment date', 'slashbooking'), 'value' => $b->slot()->start->format('Y-m-d H:i')],
                    ['name' => __('Timezone', 'slashbooking'),  'value' => $b->timezone()],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }
}
