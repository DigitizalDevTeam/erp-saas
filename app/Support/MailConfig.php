<?php

namespace App\Support;

use App\Models\Utility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class MailConfig
{
    private const FIELDS = [
        'mail_driver',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    /**
     * Resolve the SMTP settings an email should use.
     *
     * Source of truth is the Email Settings saved on /systems (settings
     * table, created_by = 1), with per-company settings taking priority
     * field-by-field when that company has filled its own values in.
     *
     * @return array<string, string>
     */
    public static function resolve(?int $createdBy = null): array
    {
        try {
            $primaryId = $createdBy && $createdBy > 0
                ? $createdBy
                : (Auth::check() ? (int) Auth::user()->creatorId() : 1);

            $primary = Utility::settingsById($primaryId);
            $global = $primaryId === 1 ? $primary : Utility::settingsById(1);

            return self::mergeSettings(
                is_array($primary) ? $primary : [],
                is_array($global) ? $global : []
            );
        } catch (\Throwable $e) {
            return self::mergeSettings([], []);
        }
    }

    /**
     * Per-field merge: the first non-empty value wins.
     *
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $fallback
     * @return array<string, string>
     */
    public static function mergeSettings(array $primary, array $fallback): array
    {
        $merged = [];

        foreach (self::FIELDS as $field) {
            $value = $primary[$field] ?? '';
            if (! is_string($value) || trim($value) === '') {
                $value = $fallback[$field] ?? '';
            }

            $merged[$field] = is_string($value) ? $value : (string) $value;
        }

        return $merged;
    }

    /**
     * Apply resolved (or explicitly overridden) SMTP settings to Laravel's
     * runtime mail config, using the keys Laravel 11 actually reads
     * (mail.default / mail.mailers.smtp.*), then forget any mailer that was
     * already resolved in this request so the new config takes effect.
     *
     * Empty values never overwrite the .env mail config, so a server with a
     * working .env SMTP keeps working until the /systems fields are filled.
     *
     * @param  array<string, mixed>|null  $overrideSettings  Explicit mail_* values (e.g. the test-mail form).
     * @return array<string, string> The settings that were applied.
     */
    public static function apply(?int $createdBy = null, ?array $overrideSettings = null): array
    {
        $settings = self::resolve($createdBy);

        if ($overrideSettings !== null) {
            $settings = self::mergeSettings($overrideSettings, $settings);
        }

        try {
            $driver = strtolower(trim($settings['mail_driver'] ?? '')) ?: 'smtp';
            $knownDrivers = ['smtp', 'sendmail', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend', 'log', 'array', 'failover', 'roundrobin'];
            if (! in_array($driver, $knownDrivers, true)) {
                $driver = 'smtp';
            }

            $config = [
                'mail.default' => $driver,
            ];

            if (trim($settings['mail_host'] ?? '') !== '') {
                $config['mail.mailers.smtp.host'] = trim($settings['mail_host']);
            }
            if (trim($settings['mail_port'] ?? '') !== '') {
                $config['mail.mailers.smtp.port'] = (int) $settings['mail_port'];
            }

            $encryption = strtolower(trim($settings['mail_encryption'] ?? ''));
            $config['mail.mailers.smtp.encryption'] = in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null;

            if (($settings['mail_username'] ?? '') !== '') {
                $config['mail.mailers.smtp.username'] = $settings['mail_username'];
            }
            if (($settings['mail_password'] ?? '') !== '') {
                $config['mail.mailers.smtp.password'] = $settings['mail_password'];
            }
            if (trim($settings['mail_from_address'] ?? '') !== '') {
                $config['mail.from.address'] = trim($settings['mail_from_address']);
            }
            if (trim($settings['mail_from_name'] ?? '') !== '') {
                $config['mail.from.name'] = trim($settings['mail_from_name']);
            }

            config($config);

            self::forgetResolvedMailer($driver);
        } catch (\Throwable $e) {
            // Never block the caller: sending will fall back to .env config.
        }

        return $settings;
    }

    private static function forgetResolvedMailer(string $driver): void
    {
        try {
            Mail::purge($driver);
        } catch (\Throwable $e) {
        }

        try {
            Mail::purge('smtp');
        } catch (\Throwable $e) {
        }

        try {
            app()->forgetInstance('mailer');
        } catch (\Throwable $e) {
        }
    }
}
