<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Platform\PasswordResetNotificationClient;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'platform_role', 'password', 'must_change_password', 'password_changed_at', 'temporary_password_expires_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'temporary_password_expires_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserTenantMembership::class);
    }

    public function internalNotifications(): HasMany
    {
        return $this->hasMany(InternalNotification::class);
    }

    public function legalAcceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    public function tenantRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class, 'requested_by_user_id');
    }

    /**
     * Envia la notificacion de restablecimiento de contrasena.
     *
     * El correo se delega al servicio central de notificaciones (stelfaro-notifications),
     * que resuelve el remitente desde el gestor de alias (purpose: platform_password_reset).
     * Si el servicio no esta configurado, se usa la notificacion nativa de Laravel.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $delivered = app(PasswordResetNotificationClient::class)->send($this, $token);

        if ($delivered === null) {
            $this->notify(new ResetPassword($token));
        }
    }

    public function hasExpiredTemporaryPassword(): bool
    {
        return $this->must_change_password
            && $this->temporary_password_expires_at !== null
            && $this->temporary_password_expires_at->isPast();
    }
}
