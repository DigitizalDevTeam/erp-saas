<?php

namespace App\Services;

use App\Models\Utility;
use Twilio\Rest\Client;

class OperationalMessagingService
{
    public static function twilioConfigured(array $settings): bool
    {
        return !empty($settings['twilio_sid'])
            && !empty($settings['twilio_token'])
            && !empty($settings['twilio_from']);
    }

    /**
     * @param  'sms'|'whatsapp'  $channel
     */
    public static function send(int $companyCreatorId, string $toRaw, string $body, string $channel = 'sms'): bool
    {
        $settings = Utility::settingsById($companyCreatorId);
        if (!self::twilioConfigured($settings)) {
            return false;
        }

        $to = self::normalizeE164($toRaw);
        if ($to === '') {
            return false;
        }

        $from = trim((string) $settings['twilio_from']);
        if ($channel === 'whatsapp') {
            $to = str_starts_with($to, 'whatsapp:') ? $to : 'whatsapp:' . $to;
            if (!str_starts_with($from, 'whatsapp:')) {
                $from = 'whatsapp:' . preg_replace('/^\+/', '', $from);
            }
        }

        try {
            $client = new Client($settings['twilio_sid'], $settings['twilio_token']);
            $client->messages->create($to, [
                'from' => $from,
                'body' => $body,
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function normalizeE164(string $raw): string
    {
        $s = preg_replace('/[^\d+]/', '', trim($raw));
        if ($s === '') {
            return '';
        }
        if ($s[0] !== '+') {
            $s = '+' . ltrim($s, '+');
        }

        return $s;
    }
}
