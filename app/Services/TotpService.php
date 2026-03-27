<?php

namespace App\Services;

class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecretKey(int $length = 32): string
    {
        $chars = self::BASE32_ALPHABET;
        $max = strlen($chars) - 1;
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, $max)];
        }

        return $secret;
    }

    public function getQRCodeUrl(string $appName, string $email, string $secret): string
    {
        $issuer = rawurlencode($appName);
        $label = rawurlencode($appName . ':' . $email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function verifyKey(string $secret, string $code, int $window = 1): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timeSlice = (int) floor(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            $calculated = $this->calculateCode($secret, $timeSlice + $i);
            if (hash_equals($calculated, $code)) {
                return true;
            }
        }

        return false;
    }

    private function calculateCode(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
        $code = $truncated % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        $alphabet = array_flip(str_split(self::BASE32_ALPHABET));
        $binary = '';

        foreach (str_split($secret) as $char) {
            if (!isset($alphabet[$char])) {
                continue;
            }
            $binary .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $result .= chr(bindec($byte));
            }
        }

        return $result;
    }
}
