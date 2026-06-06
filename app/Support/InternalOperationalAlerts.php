<?php

namespace App\Support;

use App\Mail\InternalOperationalAlertMail;
use App\Models\Utility;
use App\Services\OperationalMessagingService;
use App\Support\LoggedMail;

class InternalOperationalAlerts
{
    /** @var 'invoice_sent'|'invoice_payment'|'bill_sent'|'bill_payment' */
    public static function dispatch(int $companyCreatorId, string $event, string $subjectLine, string $plainBody): void
    {
        $settings = Utility::settingsById($companyCreatorId);

        $emailKey = match ($event) {
            'invoice_sent' => 'internal_ops_invoice',
            'invoice_payment' => 'internal_ops_invoice_payment',
            'bill_sent' => 'internal_ops_bill',
            'bill_payment' => 'internal_ops_bill_payment',
            default => null,
        };
        if ($emailKey === null) {
            return;
        }

        $emailOn = ($settings[$emailKey . '_email_enabled'] ?? 'off') === 'on';
        $smsOn = ($settings[$emailKey . '_sms_enabled'] ?? 'off') === 'on';
        $waOn = ($settings[$emailKey . '_whatsapp_enabled'] ?? 'off') === 'on';

        if ($emailOn) {
            $raw = (string) ($settings[$emailKey . '_email_recipients'] ?? '');
            $emails = array_values(array_filter(array_map('trim', preg_split('/[,\s;]+/', $raw))));
            if (count($emails) > 0) {
                self::configureMailFromCompany($companyCreatorId);
                foreach ($emails as $addr) {
                    if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        try {
                            LoggedMail::send($addr, new InternalOperationalAlertMail($subjectLine, $plainBody), [
                                'source' => 'internal_ops_' . $event,
                                'subject' => $subjectLine,
                                'created_by' => $companyCreatorId,
                            ]);
                        } catch (\Throwable $e) {
                        }
                    }
                }
            }
        }

        $phonesRaw = (string) ($settings[$emailKey . '_sms_recipients'] ?? '');
        $phones = array_values(array_filter(array_map('trim', preg_split('/[,\s;]+/', $phonesRaw))));

        foreach ($phones as $phone) {
            if ($smsOn) {
                OperationalMessagingService::send($companyCreatorId, $phone, $plainBody, 'sms');
            }
            if ($waOn) {
                OperationalMessagingService::send($companyCreatorId, $phone, $plainBody, 'whatsapp');
            }
        }
    }

    private static function configureMailFromCompany(int $companyCreatorId): void
    {
        $s = Utility::settingsById($companyCreatorId);
        $fallback = Utility::settings();
        try {
            config([
                'mail.driver' => $s['mail_driver'] ?: ($fallback['mail_driver'] ?? 'smtp'),
                'mail.host' => $s['mail_host'] ?: ($fallback['mail_host'] ?? ''),
                'mail.port' => $s['mail_port'] ?: ($fallback['mail_port'] ?? ''),
                'mail.encryption' => $s['mail_encryption'] ?: ($fallback['mail_encryption'] ?? ''),
                'mail.username' => $s['mail_username'] ?: ($fallback['mail_username'] ?? ''),
                'mail.password' => $s['mail_password'] ?: ($fallback['mail_password'] ?? ''),
                'mail.from.address' => $s['mail_from_address'] ?: ($fallback['mail_from_address'] ?? ''),
                'mail.from.name' => $s['mail_from_name'] ?: ($fallback['mail_from_name'] ?? ''),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
