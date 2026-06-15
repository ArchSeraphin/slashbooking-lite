<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

/**
 * @phpstan-type Tag array{name:string, category:string, description:string, raw:bool}
 */
final class TagRegistry
{
    private const RAW_TAGS = ['cancel_url', 'confirm_url', 'reject_url', 'ics_url', 'company_logo'];

    /** @var array<string, Tag>|null */
    private ?array $tags = null;

    /**
     * Built lazily (not in the constructor) so the __() description strings are
     * translated at request time — after the textdomain is loaded on `init` —
     * rather than at plugin boot.
     *
     * @return array<string, Tag>
     */
    private function tags(): array
    {
        return $this->tags ??= $this->buildTags();
    }

    /**
     * @return Tag|null
     */
    public function find(string $name): ?array
    {
        return $this->tags()[$name] ?? null;
    }

    /**
     * @return array<string, list<Tag>>
     */
    public function grouped(): array
    {
        $out = [];
        foreach ($this->tags() as $tag) {
            $out[$tag['category']][] = $tag;
        }
        return $out;
    }

    /**
     * @return array<string, Tag>
     */
    private function buildTags(): array
    {
        $defs = [
            ['customer', 'customer_name',    __('Customer name', 'slashbooking')],
            ['customer', 'customer_email',   __('Customer email', 'slashbooking')],
            ['customer', 'customer_phone',   __('Customer phone', 'slashbooking')],
            ['customer', 'customer_address', __('Customer address', 'slashbooking')],
            ['appointment', 'service_name',     __('Service name', 'slashbooking')],
            ['appointment', 'service_duration', __('Service duration', 'slashbooking')],
            ['appointment', 'appointment_date', __('Appointment date (long, locale)', 'slashbooking')],
            ['appointment', 'appointment_time', __('Start time (HH:mm)', 'slashbooking')],
            ['appointment', 'appointment_end',  __('End time (HH:mm)', 'slashbooking')],
            ['appointment', 'timezone',         __('Time zone', 'slashbooking')],
            ['appointment', 'notes',            __('Customer notes', 'slashbooking')],
            ['actions', 'cancel_url',  __('Customer cancellation URL', 'slashbooking')],
            ['actions', 'confirm_url', __('Admin confirmation URL', 'slashbooking')],
            ['actions', 'reject_url',  __('Admin decline URL', 'slashbooking')],
            ['actions', 'ics_url',     __('.ics download URL', 'slashbooking')],
            ['site', 'site_name',     __('Site name', 'slashbooking')],
            ['site', 'site_url',      __('Site URL', 'slashbooking')],
            ['site', 'admin_email',   __('Admin email', 'slashbooking')],
            ['site', 'company_logo',  __('Logo <img> tag (plugin option)', 'slashbooking')],
            ['site', 'company_phone', __('Company phone (plugin option)', 'slashbooking')],
        ];
        $out = [];
        foreach ($defs as [$cat, $name, $desc]) {
            $out[$name] = [
                'name'        => $name,
                'category'    => $cat,
                'description' => $desc,
                'raw'         => in_array($name, self::RAW_TAGS, true),
            ];
        }
        return $out;
    }
}
