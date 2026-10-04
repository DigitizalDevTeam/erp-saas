<?php

namespace App\Support;

use App\Services\EmailLogService;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class LoggedMail
{
    public static function send($to, Mailable $mailable, array $context = []): void
    {
        $recipients = self::normalizeRecipients($to);
        $source = $context['source'] ?? class_basename($mailable);
        $subject = $context['subject'] ?? self::resolveSubject($mailable);
        $createdBy = $context['created_by'] ?? (Auth::check() ? Auth::user()->creatorId() : 0);
        $userId = $context['user_id'] ?? Auth::id();
        $mailSettings = $context['mail_settings'] ?? null;
        $meta = array_merge($context, [
            'mailable_class' => get_class($mailable),
        ]);
        unset($meta['source'], $meta['subject'], $meta['created_by'], $meta['user_id'], $meta['mail_settings']);

        // Every email goes out with the SMTP settings saved on /systems
        // (per-company values win field-by-field, /systems is the fallback),
        // unless the caller supplied explicit settings (test-mail form).
        MailConfig::apply($createdBy ? (int) $createdBy : null, is_array($mailSettings) ? $mailSettings : null);

        try {
            Mail::to($to)->send($mailable);

            EmailLogService::logSuccess(
                $recipients,
                $subject,
                $source,
                (int) $createdBy,
                $userId ? (int) $userId : null,
                $meta
            );
        } catch (\Throwable $e) {
            EmailLogService::logFailure(
                $recipients,
                $subject,
                $source,
                $e->getMessage(),
                (int) $createdBy,
                $userId ? (int) $userId : null,
                $meta
            );

            throw $e;
        }
    }

    private static function normalizeRecipients($to): string
    {
        if (is_string($to)) {
            return $to;
        }

        if (! is_array($to)) {
            return (string) $to;
        }

        $emails = [];

        foreach ($to as $key => $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $value;
            } elseif (is_string($key) && filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $key;
            } elseif (is_numeric($key) && is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $value;
            }
        }

        return implode(', ', array_unique($emails));
    }

    private static function resolveSubject(Mailable $mailable): ?string
    {
        if (property_exists($mailable, 'subjectLine') && ! empty($mailable->subjectLine)) {
            return (string) $mailable->subjectLine;
        }

        if (property_exists($mailable, 'template') && is_object($mailable->template) && ! empty($mailable->template->subject)) {
            return (string) $mailable->template->subject;
        }

        foreach (['lArr', 'dArr'] as $property) {
            if (property_exists($mailable, $property) && is_array($mailable->{$property}) && ! empty($mailable->{$property}['subject'])) {
                return (string) $mailable->{$property}['subject'];
            }
        }

        return class_basename($mailable);
    }
}
