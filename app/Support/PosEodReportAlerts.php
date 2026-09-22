<?php

namespace App\Support;

use App\Mail\PosEodReportMail;
use App\Models\PosShift;
use App\Models\Utility;
use App\Services\OperationalMessagingService;
use App\Services\PosEodReportService;

class PosEodReportAlerts
{
    private const SETTING_PREFIX = 'internal_ops_report_received';

    /**
     * Send EOD PDF to "Report Received At" recipients after a restaurant shift close.
     */
    public static function sendOnShiftClose(PosShift $shift): void
    {
        try {
            $shift->loadMissing('branch');
            if (! $shift->branch || ! $shift->branch->isRestaurant()) {
                return;
            }

            $creatorId = (int) ($shift->created_by ?: 0);
            if ($creatorId <= 0) {
                return;
            }

            $date = $shift->opened_at
                ? $shift->opened_at->format('Y-m-d')
                : date('Y-m-d');

            self::sendForDate($creatorId, $date, $shift);
        } catch (\Throwable $e) {
            // Never block shift close on report email failure.
        }
    }

    /**
     * Send EOD PDF once for a company/date (e.g. after bulk EOD close).
     */
    public static function sendForDate(int $creatorId, string $date, ?PosShift $contextShift = null): void
    {
        try {
            $settings = Utility::settingsById($creatorId);
            $emailOn = ($settings[self::SETTING_PREFIX . '_email_enabled'] ?? 'off') === 'on';
            $smsOn = ($settings[self::SETTING_PREFIX . '_sms_enabled'] ?? 'off') === 'on';
            $waOn = ($settings[self::SETTING_PREFIX . '_whatsapp_enabled'] ?? 'off') === 'on';

            if (! $emailOn && ! $smsOn && ! $waOn) {
                return;
            }

            $plainNotice = __('EOD report for :date has been generated.', ['date' => $date]);
            if ($contextShift) {
                $contextShift->loadMissing(['branch', 'user']);
                $plainNotice .= "\n"
                    . __('Shift') . ': #' . $contextShift->id . "\n"
                    . __('Branch') . ': ' . ($contextShift->branch->name ?? '-') . "\n"
                    . __('User') . ': ' . ($contextShift->user->name ?? '-');
            }

            if ($emailOn) {
                $raw = (string) ($settings[self::SETTING_PREFIX . '_email_recipients'] ?? '');
                $emails = array_values(array_filter(array_map('trim', preg_split('/[,\s;]+/', $raw) ?: [])));
                $emails = array_values(array_filter($emails, fn ($addr) => filter_var($addr, FILTER_VALIDATE_EMAIL)));

                if (count($emails) > 0) {
                    self::configureMailFromCompany($creatorId);
                    $pdfBinary = PosEodReportService::renderPdf($creatorId, $date);
                    $fileName = 'EOD_Report_' . $date . '.pdf';
                    $subject = __('EOD Report') . ' — ' . $date;
                    $body = $plainNotice . "\n\n" . __('Please find the End of Day report attached as a PDF.');

                    foreach ($emails as $addr) {
                        try {
                            LoggedMail::send($addr, new PosEodReportMail($subject, $body, $pdfBinary, $fileName), [
                                'source' => 'pos_eod_report',
                                'subject' => $subject,
                                'created_by' => $creatorId,
                            ]);
                        } catch (\Throwable $e) {
                        }
                    }
                }
            }

            $phonesRaw = (string) ($settings[self::SETTING_PREFIX . '_sms_recipients'] ?? '');
            $phones = array_values(array_filter(array_map('trim', preg_split('/[,\s;]+/', $phonesRaw) ?: [])));
            foreach ($phones as $phone) {
                if ($smsOn) {
                    OperationalMessagingService::send($creatorId, $phone, $plainNotice, 'sms');
                }
                if ($waOn) {
                    OperationalMessagingService::send($creatorId, $phone, $plainNotice, 'whatsapp');
                }
            }
        } catch (\Throwable $e) {
            // Swallow — shift close must not fail.
        }
    }

    private static function configureMailFromCompany(int $companyCreatorId): void
    {
        $s = Utility::settingsById($companyCreatorId);
        $fallback = Utility::settings();
        try {
            $driver = (string) ($s['mail_driver'] ?: ($fallback['mail_driver'] ?? 'smtp'));
            $host = (string) ($s['mail_host'] ?: ($fallback['mail_host'] ?? ''));
            $port = (string) ($s['mail_port'] ?: ($fallback['mail_port'] ?? ''));
            $encryption = (string) ($s['mail_encryption'] ?: ($fallback['mail_encryption'] ?? ''));
            $username = (string) ($s['mail_username'] ?: ($fallback['mail_username'] ?? ''));
            $password = (string) ($s['mail_password'] ?: ($fallback['mail_password'] ?? ''));
            $fromAddress = (string) ($s['mail_from_address'] ?: ($fallback['mail_from_address'] ?? ''));
            $fromName = (string) ($s['mail_from_name'] ?: ($fallback['mail_from_name'] ?? ''));

            config([
                'mail.driver' => $driver,
                'mail.host' => $host,
                'mail.port' => $port,
                'mail.encryption' => $encryption,
                'mail.username' => $username,
                'mail.password' => $password,
                'mail.from.address' => $fromAddress,
                'mail.from.name' => $fromName,
                'mail.default' => $driver !== '' ? $driver : (config('mail.default') ?: 'smtp'),
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => $port !== '' ? (int) $port : 587,
                'mail.mailers.smtp.encryption' => $encryption !== '' ? $encryption : null,
                'mail.mailers.smtp.username' => $username,
                'mail.mailers.smtp.password' => $password,
                'mail.from.address' => $fromAddress !== '' ? $fromAddress : (config('mail.from.address') ?: 'no-reply@example.com'),
                'mail.from.name' => $fromName !== '' ? $fromName : (config('mail.from.name') ?: config('app.name')),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
