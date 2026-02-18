<?php

namespace App\Services;

use App\Models\PosAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class PosAuditLogService
{
    /**
     * Phase 4: Require manager role and matching password for sensitive actions.
     */
    public static function requireManagerPassword(Request $request): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        if (!$user->can('pos restaurant manager') && !$user->can('pos restaurant full') && !$user->can('manage pos')) {
            return false;
        }
        $password = $request->input('manager_password');
        if ($password === null || $password === '') {
            return false;
        }
        return Hash::check($password, $user->password);
    }

    public static function log(
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $description = null,
        array $meta = []
    ): PosAuditLog {
        $user = Auth::user();
        return PosAuditLog::create([
            'created_by' => $user ? $user->creatorId() : 0,
            'user_id' => $user ? $user->id : 0,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'meta' => $meta ?: null,
            'ip' => request()->ip(),
        ]);
    }
}
