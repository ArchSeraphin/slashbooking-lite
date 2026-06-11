<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Persistence\ServiceRepository;
use Slash\Booking\Plugin;
use DateTimeImmutable;
use DateTimeZone;

/**
 * WP dashboard widget that surfaces what the booking owner actually needs to
 * see when they log in: pending requests awaiting their decision, and the
 * upcoming confirmed schedule for the next 7 days. Visible to anyone with the
 * {@see Capabilities::VIEW} cap (administrators by default; extendable via the
 * 'slashbooking_manage_roles' filter).
 */
final class DashboardWidget
{
    private const WIDGET_ID       = 'slashbooking_dashboard';
    private const PENDING_LIMIT   = 5;
    private const UPCOMING_LIMIT  = 5;
    private const UPCOMING_DAYS   = 7;

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly ServiceRepository $services,
    ) {
    }

    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'addWidget']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Enqueue the widget stylesheet on the dashboard screen only. The widget
     * markup itself ships no inline <style>; all styling lives in this file.
     */
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'index.php' || !current_user_can(Capabilities::VIEW)) {
            return;
        }
        wp_enqueue_style(
            'slashbooking-dashboard',
            plugin_dir_url(Plugin::instance()->pluginFile()) . 'assets/dashboard.css',
            [],
            Plugin::VERSION,
        );
    }

    public function addWidget(): void
    {
        if (!current_user_can(Capabilities::VIEW)) {
            return;
        }
        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('SlashBooking — Réservations', 'slashbooking'),
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $upcomingEnd = $now->modify('+' . self::UPCOMING_DAYS . ' days');

        $pending = $this->bookings->paginate(
            ['status' => BookingStatus::PENDING->value, 'from' => $now],
            page: 1,
            perPage: self::PENDING_LIMIT,
        );
        $upcoming = $this->bookings->paginate(
            ['status' => BookingStatus::CONFIRMED->value, 'from' => $now, 'to' => $upcomingEnd],
            page: 1,
            perPage: self::UPCOMING_LIMIT,
        );

        $serviceNames = $this->buildServiceNameMap();
        $manageUrl    = admin_url('admin.php?page=slashbooking#/bookings');

        echo '<div class="sb-dash">';

        $this->renderSection(
            heading: __('À valider', 'slashbooking'),
            badge:   $pending['total'],
            badgeTone: 'warn',
            emptyText: __('Aucune demande en attente.', 'slashbooking'),
            items:   $pending['items'],
            services: $serviceNames,
            tz:      $tz,
        );

        $this->renderSection(
            heading: sprintf(
                /* translators: %d: number of days */
                __('À venir (%d jours)', 'slashbooking'),
                self::UPCOMING_DAYS
            ),
            badge:   $upcoming['total'],
            badgeTone: 'info',
            emptyText: __('Pas de rendez-vous prévu cette semaine.', 'slashbooking'),
            items:   $upcoming['items'],
            services: $serviceNames,
            tz:      $tz,
        );

        echo '<p class="sb-dash__footer"><a href="' . esc_url($manageUrl) . '">'
            . esc_html__('Voir toutes les réservations →', 'slashbooking')
            . '</a></p>';
        echo '</div>';
    }

    /**
     * @param list<Booking> $items
     * @param array<int, string> $services map of service_id → name
     */
    private function renderSection(
        string $heading,
        int $badge,
        string $badgeTone,
        string $emptyText,
        array $items,
        array $services,
        DateTimeZone $tz,
    ): void {
        echo '<section class="sb-dash__section">';
        echo '<h3 class="sb-dash__heading">';
        echo esc_html($heading);
        echo ' <span class="sb-dash__badge sb-dash__badge--' . esc_attr($badgeTone) . '">'
            . esc_html((string) $badge) . '</span>';
        echo '</h3>';

        if ($items === []) {
            echo '<p class="sb-dash__empty">' . esc_html($emptyText) . '</p>';
            echo '</section>';
            return;
        }

        echo '<ul class="sb-dash__list">';
        foreach ($items as $b) {
            $startLocal = $b->slot()->start->setTimezone($tz);
            $when = wp_date(
                /* translators: WP date+time format for compact booking display */
                _x('D j M, H\hi', 'compact dashboard datetime', 'slashbooking'),
                $startLocal->getTimestamp(),
                $tz,
            );
            // wp_date() returns false on locale failure — fall back to ISO so
            // the widget never renders a broken row.
            if (!is_string($when)) {
                $when = $startLocal->format('Y-m-d H:i');
            }
            $serviceName = $services[$b->serviceId()] ?? __('Service', 'slashbooking');

            echo '<li class="sb-dash__row">';
            echo '<span class="sb-dash__when">' . esc_html($when) . '</span>';
            echo '<span class="sb-dash__who">' . esc_html($b->customerName()) . '</span>';
            echo '<span class="sb-dash__svc" title="' . esc_attr($serviceName) . '">'
                . esc_html($serviceName) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</section>';
    }

    /**
     * @return array<int, string>
     */
    private function buildServiceNameMap(): array
    {
        $map = [];
        foreach ($this->services->findAll() as $svc) {
            if ($svc->id !== null) {
                $map[$svc->id] = $svc->name;
            }
        }
        return $map;
    }
}
