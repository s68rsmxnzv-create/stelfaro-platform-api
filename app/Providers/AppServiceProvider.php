<?php

namespace App\Providers;

use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\WorkshopOrder;
use App\Models\WorkshopOrderPayment;
use App\Observers\SalesOrderObserver;
use App\Observers\SalesOrderPaymentObserver;
use App\Observers\WorkshopOrderObserver;
use App\Observers\WorkshopOrderPaymentObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        WorkshopOrder::observe(WorkshopOrderObserver::class);
        WorkshopOrderPayment::observe(WorkshopOrderPaymentObserver::class);
        SalesOrder::observe(SalesOrderObserver::class);
        SalesOrderPayment::observe(SalesOrderPaymentObserver::class);
        Vite::prefetch(concurrency: 3);

        $this->configurePasswordResetMail();
    }

    /**
     * Plantilla de reserva (en español) del correo de restablecimiento.
     *
     * El flujo normal delega el envío al servicio de notificaciones
     * (ver User::sendPasswordResetNotification). Esta plantilla solo se
     * usa si ese servicio no está configurado en el entorno. El remitente
     * NO se fija aquí: lo resuelve el gestor de alias del servicio, o el
     * remitente global (config/mail.php) como último recurso.
     */
    private function configurePasswordResetMail(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $expira = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return (new MailMessage)
                ->subject('Restablece tu contraseña')
                ->greeting('¡Hola!')
                ->line('Recibes este correo porque solicitamos un restablecimiento de contraseña para tu cuenta en StelFaro.')
                ->action('Restablecer contraseña', $url)
                ->line("Este enlace vencerá en {$expira} minutos.")
                ->line('Si no solicitaste el restablecimiento, no necesitas hacer nada.')
                ->salutation("Saludos,\nStelFaro");
        });
    }
}
