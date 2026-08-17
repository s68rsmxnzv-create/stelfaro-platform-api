<?php

namespace App\Http\Requests\Auth;

use App\Models\SecurityEvent;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            $this->recordLoginSecurityEvent('auth.login_failed', 'warning');

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if (Auth::user()?->hasExpiredTemporaryPassword()) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());
            $this->recordLoginSecurityEvent('auth.temporary_password_expired', 'warning');

            throw ValidationException::withMessages([
                'email' => ['Tu contraseña temporal expiró. Solicita a un administrador que genere una nueva.'],
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));
        $this->recordLoginSecurityEvent('auth.login_lockout', 'high');

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    private function recordLoginSecurityEvent(string $type, string $severity): void
    {
        $email = strtolower(trim((string) $this->input('email')));
        $attributes = [
            'user_id' => null,
            'type' => $type,
            'severity' => $severity,
            'ip_address' => $this->ip(),
            'user_agent' => Str::limit($this->safeAuditText($this->userAgent()), 1000, ''),
            'method' => $this->method(),
            'url' => Str::limit($this->fullUrl(), 2000, ''),
            'field' => 'email',
            'fingerprint' => hash('sha256', implode('|', [
                $type,
                $this->ip() ?? '',
                $this->userAgent() ?? '',
                $email,
            ])),
            'metadata' => [
                'email_sha256' => $email !== '' ? hash('sha256', $email) : null,
                'throttle_key_sha256' => hash('sha256', $this->throttleKey()),
            ],
        ];

        try {
            SecurityEvent::query()->create($attributes);
        } catch (\Throwable $exception) {
            Log::warning('No fue posible registrar evento de seguridad de login.', [
                ...$attributes,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeAuditText(?string $value): string
    {
        $value = trim((string) $value);
        $normalized = preg_replace('/\s+/', ' ', $value) ?: $value;

        return str_replace(['<', '>'], ['[', ']'], $normalized);
    }
}
