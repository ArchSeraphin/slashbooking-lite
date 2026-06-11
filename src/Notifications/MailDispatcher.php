<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

use Throwable;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Notifications\Events\BookingContext;
use Slash\Booking\Notifications\Events\EventKey;
use Slash\Booking\Persistence\MailTemplateRepository;
use Slash\Booking\Privacy\EmailMasker;

final class MailDispatcher
{
    public function __construct(
        private readonly MailTemplateRepository $templates,
        private readonly TemplateRenderer $renderer,
        private readonly TextBodyGenerator $text,
        private readonly IcsBuilder $ics,
    ) {
    }

    public function send(
        EventKey $event,
        string $recipient,
        BookingContext $context,
        ?Booking $withIcsFor = null,
    ): bool {
        try {
            $tpl  = $this->templates->getOrDefault($event);
            $data = $context->toArray();

            $subject  = $this->renderer->render($tpl['subject'], $data);
            $html     = $this->renderer->render($tpl['html_body'], $data);
            $textBody = $tpl['text_body'] !== null && $tpl['text_body'] !== ''
                ? $this->renderer->render($tpl['text_body'], $data)
                : $this->text->fromHtml($html);

            $headers = [
                'From: ' . $this->fromHeader(),
                'Reply-To: ' . $this->replyTo($recipient, $context),
                'Content-Type: text/html; charset=UTF-8',
            ];

            $attachments = [];
            if ($withIcsFor !== null) {
                $icsPath = $this->writeIcsFile($withIcsFor, $subject);
                if ($icsPath !== null) {
                    $attachments[] = $icsPath;
                }
            }

            // Tell PHPMailer to send multipart/alternative with the plain-text AltBody.
            // Manual multipart body construction passed to wp_mail() leaks boundary markers
            // because PHPMailer rewraps the whole thing as a single body part.
            $altBodyHook = static function ($phpmailer) use ($textBody): void {
                $phpmailer->AltBody = $textBody;
            };
            add_action('phpmailer_init', $altBodyHook);
            try {
                $sent = wp_mail($recipient, $subject, $html, $headers, $attachments);
            } finally {
                remove_action('phpmailer_init', $altBodyHook);
            }

            // The .ics exists in uploads only for the duration of the send.
            foreach ($attachments as $path) {
                wp_delete_file($path);
            }

            if (!$sent) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: mail dispatch failure must be logged; no WP equivalent for server-side error logging.
                error_log(sprintf('[slashbooking] wp_mail failed for event=%s to=%s', $event->value, EmailMasker::mask($recipient)));
            }
            return (bool) $sent;
        } catch (Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: fail-safe catch block; error_log is the only safe logging channel available without WP context.
            error_log(sprintf('[slashbooking] MailDispatcher exception (to=%s): %s', EmailMasker::mask($recipient), $e->getMessage()));
            return false;
        }
    }

    /**
     * Sends a raw HTML e-mail without templating. Used for "Send test" from
     * the templates editor admin SPA.
     *
     * Returns true if wp_mail() reports success.
     */
    public function sendRaw(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->fromHeader(),
        ];

        return (bool) wp_mail($to, $subject, $htmlBody, $headers);
    }

    private function fromHeader(): string
    {
        $name  = (string) get_option('blogname', 'WordPress');
        $email = (string) get_option('admin_email', 'no-reply@example.com');
        return sprintf('%s <%s>', $name, $email);
    }

    private function replyTo(string $recipient, BookingContext $ctx): string
    {
        $admin = (string) ($ctx->toArray()['admin_email'] ?? get_option('admin_email', ''));
        return $admin !== '' ? $admin : $recipient;
    }

    /**
     * Writes the booking's .ics calendar file into a dedicated, hardened folder
     * inside the WordPress uploads directory (never the plugin folder nor the
     * system temp dir) and returns its path so wp_mail() can attach it. The
     * caller deletes it immediately after the message is sent. Returns null when
     * the uploads directory is unavailable.
     */
    private function writeIcsFile(Booking $b, string $summary): ?string
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return null;
        }

        $dir = rtrim((string) $uploads['basedir'], '/\\') . '/slashbooking';
        if (!wp_mkdir_p($dir)) {
            return null;
        }
        $this->hardenDir($dir);

        $ics = $this->ics->build(
            uid: $b->publicUid() . '@slashbooking',
            summary: $summary,
            description: '',
            startUtc: $b->slot()->start,
            endUtc:   $b->slot()->end,
        );
        $path = $dir . '/sb-' . $b->publicUid() . '.ics';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- short-lived .ics attachment in a hardened uploads subfolder, deleted right after wp_mail(); WP_Filesystem is not available in the mail-dispatch context.
        if (file_put_contents($path, $ics) === false) {
            return null;
        }
        return $path;
    }

    /**
     * Drops an empty index.html into the uploads subfolder so its contents
     * cannot be listed by browsing the directory. Written once.
     */
    private function hardenDir(string $dir): void
    {
        $guard = $dir . '/index.html';
        if (file_exists($guard)) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time empty directory-listing guard.
        file_put_contents($guard, '');
    }
}
