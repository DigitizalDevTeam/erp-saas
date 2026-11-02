<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PosLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }

        if ($this->has('pos_pin')) {
            $this->merge([
                'pos_pin' => preg_replace('/\D/', '', (string) $this->input('pos_pin')),
            ]);
        }
    }

    /**
     * Two POS login modes, one endpoint:
     *  - first / changed company: email + PIN (no user list at all);
     *  - remembered company: hidden company_id + picked user_id + PIN,
     *    where the user list is scoped to that one company only.
     */
    public function isRememberedCompanyMode(): bool
    {
        return $this->filled('company_id');
    }

    public function rules(): array
    {
        $pin = ['required', 'string', 'regex:/^\d{4,8}$/'];

        if ($this->isRememberedCompanyMode()) {
            return [
                'company_id' => ['required', 'integer', 'min:1'],
                'user_id' => ['required', 'integer', 'exists:users,id'],
                'pos_pin' => $pin,
            ];
        }

        return [
            // Email is globally unique on users, so email + PIN identifies
            // exactly one user without any user list on the public page.
            'email' => ['required', 'string', 'email', 'max:255'],
            'pos_pin' => $pin,
        ];
    }

    public function normalizedEmail(): string
    {
        return strtolower(trim((string) $this->input('email', '')));
    }

    public function normalizedPin(): string
    {
        return preg_replace('/\D/', '', (string) $this->input('pos_pin', ''));
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            $this->throttleErrorKey() => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function hitRateLimit(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    public function clearRateLimit(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    public function throttleErrorKey(): string
    {
        return $this->isRememberedCompanyMode() ? 'user_id' : 'email';
    }

    public function throttleKey(): string
    {
        if ($this->isRememberedCompanyMode()) {
            // Remembered mode has no email to key on, so key on the
            // submitted company + user + IP instead.
            return 'pos|remembered|'.$this->integer('company_id').'|'.$this->integer('user_id').'|'.$this->ip();
        }

        // Keyed by email (hashed, so the raw address is not the limiter key)
        // plus IP. The old global user_id key no longer exists.
        return 'pos|email|'.hash('sha256', $this->normalizedEmail()).'|'.$this->ip();
    }
}
