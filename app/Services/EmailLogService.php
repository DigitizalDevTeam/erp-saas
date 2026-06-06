<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Support\Facades\Auth;

class EmailLogService
{
    public static function logSuccess(
        string $recipient,
        ?string $subject,
        ?string $source,
        ?int $createdBy = null,
        ?int $userId = null,
        array $meta = []
    ): EmailLog {
        return self::record(EmailLog::STATUS_SUCCESS, $recipient, $subject, $source, null, $createdBy, $userId, $meta);
    }

    public static function logFailure(
        string $recipient,
        ?string $subject,
        ?string $source,
        string $errorMessage,
        ?int $createdBy = null,
        ?int $userId = null,
        array $meta = []
    ): EmailLog {
        return self::record(EmailLog::STATUS_FAILED, $recipient, $subject, $source, $errorMessage, $createdBy, $userId, $meta);
    }

    public static function record(
        string $status,
        string $recipient,
        ?string $subject,
        ?string $source,
        ?string $errorMessage = null,
        ?int $createdBy = null,
        ?int $userId = null,
        array $meta = []
    ): EmailLog {
        if ($createdBy === null && Auth::check()) {
            $createdBy = Auth::user()->creatorId();
        }

        if ($userId === null && Auth::check()) {
            $userId = Auth::id();
        }

        return EmailLog::create([
            'created_by' => $createdBy ?? 0,
            'user_id' => $userId,
            'recipient' => mb_substr(trim($recipient), 0, 500),
            'subject' => $subject ? mb_substr($subject, 0, 255) : null,
            'source' => $source ? mb_substr($source, 0, 120) : null,
            'mailable_class' => isset($meta['mailable_class']) ? mb_substr((string) $meta['mailable_class'], 0, 120) : null,
            'status' => $status,
            'error_message' => $errorMessage,
            'meta' => empty($meta) ? null : $meta,
        ]);
    }
}
