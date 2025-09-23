<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\User;
use App\Http\Controllers\ModalPontuacaoController;
use Illuminate\Support\Facades\Log;
use App\Services\DiscordLogService;

class AtualizarPontosLogin extends Command
{
    protected $signature = 'pontos:atualizar';
    protected $description = 'Atualiza automaticamente os pontos de login dos usuários';

    public function handle()
    {
        Log::channel('cronJobs')->info('Iniciando atualização automática dos pontos de login...');
        try {
            //instancia o controller para chamar a função existente
            $controller = new ModalPontuacaoController();

            User::select('id')->chunk(100, function ($usuarios) use ($controller) {
                foreach ($usuarios as $usuario) {
                    try {
                        $controller->handleLoginPoints($usuario->id);
                    } catch (\Exception $e) {
                        DiscordLogService::logServiceError(
                            'Erro ao atualizar pontos de login de usuário',
                            $e->getMessage(),
                            [
                                'classe' => __CLASS__,
                                'método' => __FUNCTION__,
                                'usuario_id' => $usuario->id,
                                'arquivo' => $e->getFile(),
                                'linha' => $e->getLine(),
                                'trace' => $e->getTraceAsString(),
                            ]
                        );

                        Log::channel('cronJobs')->error("Erro ao atualizar pontos de um usuário. Detalhes: {$e->getMessage()}");
                    }
                }
            });

            Log::channel('cronJobs')->info('Atualização dos pontos de login concluída.');
            // Log info removido
        } catch (\Exception $e) {
            DiscordLogService::logServiceError(
                'Erro crítico ao executar atualização de pontos',
                $e->getMessage(),
                [
                    'classe' => __CLASS__,
                    'método' => __FUNCTION__,
                    'command' => 'pontos:atualizar',
                    'arquivo' => $e->getFile(),
                    'linha' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            Log::channel('cronJobs')->error("Erro crítico ao executar atualização de pontos: {$e->getMessage()}");
        }
    }
}
