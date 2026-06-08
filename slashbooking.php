<?php
/**
 * Plugin Name:       SlashBooking
 * Plugin URI:        https://slashbooking.fr/
 * Description:       Online appointment booking for WordPress: a real-time public calendar via shortcode, with one-click email confirmation. Self-hosted and GDPR-friendly.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            SlashBooking
 * Author URI:        https://slashbooking.fr/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slashbooking
 * Domain Path:       /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    return;
}
require_once $autoload;

register_activation_hook(__FILE__, [\Slash\Booking\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\Slash\Booking\Deactivator::class, 'deactivate']);

\Slash\Booking\Plugin::boot(__FILE__);
