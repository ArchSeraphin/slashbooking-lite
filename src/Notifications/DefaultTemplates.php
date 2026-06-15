<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

use Slash\Booking\Notifications\Events\EventKey;

final class DefaultTemplates
{
    /**
     * @return array<string, array{subject:string, html_body:string}>
     */
    public static function all(): array
    {
        return [
            EventKey::PENDING_CLIENT->value => [
                'subject'   => __('Your appointment request — awaiting approval', 'slashbooking'),
                'html_body' => self::pendingClient(),
            ],
            EventKey::PENDING_ADMIN->value => [
                'subject'   => __('New appointment request: {{service_name}} — {{customer_name}}', 'slashbooking'),
                'html_body' => self::pendingAdmin(),
            ],
            EventKey::CONFIRMED_CLIENT->value => [
                'subject'   => __('Appointment confirmed — {{appointment_date}} at {{appointment_time}}', 'slashbooking'),
                'html_body' => self::confirmedClient(),
            ],
            EventKey::REJECTED_CLIENT->value => [
                'subject'   => __('Your appointment request could not be confirmed', 'slashbooking'),
                'html_body' => self::rejectedClient(),
            ],
            EventKey::CANCELLED_CLIENT->value => [
                'subject'   => __('Your appointment cancellation is confirmed', 'slashbooking'),
                'html_body' => self::cancelledClient(),
            ],
            EventKey::REMINDER_CLIENT->value => [
                'subject'   => __('Reminder: appointment tomorrow at {{appointment_time}}', 'slashbooking'),
                'html_body' => self::reminderClient(),
            ],
        ];
    }

    private static function pendingClient(): string
    {
        return __('<p>Hi {{customer_name}},</p>
<p>We have received your appointment request for <strong>{{service_name}}</strong> on <strong>{{appointment_date}}</strong> at <strong>{{appointment_time}}</strong>.</p>
<p>Our team will get back to you shortly to confirm it.</p>
<p>You can cancel at any time: <a href="{{cancel_url}}">cancel this appointment</a>.</p>
<p>— {{site_name}}</p>', 'slashbooking');
    }

    private static function pendingAdmin(): string
    {
        return __('<p>New appointment request to review:</p>
<ul>
  <li><strong>Service:</strong> {{service_name}} ({{service_duration}})</li>
  <li><strong>When:</strong> {{appointment_date}} from {{appointment_time}} to {{appointment_end}}</li>
  <li><strong>Customer:</strong> {{customer_name}} — {{customer_email}} — {{customer_phone}}</li>
  <li><strong>Address:</strong> {{customer_address}}</li>
  <li><strong>Notes:</strong> {{notes}}</li>
</ul>
<p>
  <a href="{{confirm_url}}" style="background:#16a34a;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;">Confirm</a>
  &nbsp;
  <a href="{{reject_url}}" style="background:#dc2626;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;">Decline</a>
</p>
<p style="font-size:12px;color:#666">The links expire in 72 h.</p>', 'slashbooking');
    }

    private static function confirmedClient(): string
    {
        return __('<p>Hi {{customer_name}},</p>
<p>Your appointment <strong>{{service_name}}</strong> is confirmed for <strong>{{appointment_date}}</strong> from {{appointment_time}} to {{appointment_end}} ({{timezone}}).</p>
<p>Address provided: {{customer_address}}</p>
<p>You can add it to your calendar via the attached .ics file, or <a href="{{cancel_url}}">cancel this appointment</a>.</p>
<p>See you soon!<br>{{site_name}}</p>', 'slashbooking');
    }

    private static function rejectedClient(): string
    {
        return __('<p>Hi {{customer_name}},</p>
<p>Sorry, your appointment request for {{appointment_date}} at {{appointment_time}} could not be confirmed.</p>
<p>Feel free to <a href="{{site_url}}">pick another slot</a>.</p>
<p>— {{site_name}}</p>', 'slashbooking');
    }

    private static function cancelledClient(): string
    {
        return __('<p>Hi {{customer_name}},</p>
<p>We have registered the cancellation of your appointment {{service_name}} on {{appointment_date}} at {{appointment_time}}.</p>
<p>See you soon! <a href="{{site_url}}">Book another appointment</a>.</p>', 'slashbooking');
    }

    private static function reminderClient(): string
    {
        return __('<p>Hi {{customer_name}},</p>
<p>Just a reminder: your appointment <strong>{{service_name}}</strong> is scheduled for <strong>tomorrow</strong> at {{appointment_time}} ({{timezone}}).</p>
<p>Address: {{customer_address}}</p>
<p>Need to cancel? <a href="{{cancel_url}}">Click here</a>.</p>
<p>— {{site_name}}</p>', 'slashbooking');
    }
}
