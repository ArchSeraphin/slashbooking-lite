<?php
declare(strict_types=1);

namespace Slash\Booking\Admin;

final class AdminMenu
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            page_title: __('SlashBooking', 'slashbooking'),
            menu_title: __('SlashBooking', 'slashbooking'),
            capability: Capabilities::VIEW,
            menu_slug:  'slashbooking',
            callback:   [$this, 'render'],
            icon_url:   'dashicons-calendar-alt',
            position:   25,
        );

        $this->addQuickLinks();
    }

    /**
     * Quick-access submenu items that deep-link to the single-page React
     * admin via the URL hash (#/<tab>). The app listens for `hashchange`,
     * so these switch tabs even when the page is already open.
     */
    private function addQuickLinks(): void
    {
        $tabs = [
            'bookings'  => __('Réservations', 'slashbooking'),
            'services'  => __('Services', 'slashbooking'),
            'google'    => __('Google', 'slashbooking'),
            'templates' => __('Templates', 'slashbooking'),
            'settings'  => __('Réglages', 'slashbooking'),
        ];

        global $submenu;
        foreach ($tabs as $tab => $label) {
            $submenu['slashbooking'][] = [
                $label,
                Capabilities::VIEW,
                'admin.php?page=slashbooking#/' . $tab,
            ];
        }
    }

    public function render(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('SlashBooking', 'slashbooking') . '</h1>';
        echo '<div id="sb-admin-app"></div></div>';
    }
}
