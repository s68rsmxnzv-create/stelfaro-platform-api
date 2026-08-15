<?php

namespace App\Console\Commands;

use App\Services\AgentReleaseNotificationGenerator;
use Illuminate\Console\Command;

class GenerateAgentReleaseNotifications extends Command
{
    protected $signature = 'agent-notifications:generate {platform : windows|android} {version : Versión publicada, ej. 1.2.0}';

    protected $description = 'Notifica a los usuarios activos que hay una nueva versión del agente/app de impresión disponible';

    public function handle(AgentReleaseNotificationGenerator $generator): int
    {
        $platform = (string) $this->argument('platform');
        $version = (string) $this->argument('version');

        if (! in_array($platform, ['windows', 'android'], true)) {
            $this->error('Plataforma inválida. Usa windows o android.');

            return self::FAILURE;
        }

        $created = $generator->generate($platform, $version);
        $this->info("Notificaciones creadas: {$created}");

        return self::SUCCESS;
    }
}
