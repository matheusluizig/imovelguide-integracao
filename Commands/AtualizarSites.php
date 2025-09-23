<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\SiteCorretor\SiteCorretorDominiosController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\DiscordLogService;

class AtualizarSites extends Command
{
  protected $signature = 'atualizarSites';
  protected $description = 'Gerencia sites: desativa sem plano e ativa com plano';

  public function __construct()
  {
    parent::__construct();
  }

  public function handle()
  {

    try {
      $this->desativarSitesSemPlano();
      $this->ativarSitesComPlano();
    } catch (\Exception $e) {
      DiscordLogService::logServiceError(
        'AtualizarSites@handle',
        'Erro crítico no comando atualizarSites: ' . $e->getMessage(),
        [
          'command' => 'atualizarSites',
          'file' => $e->getFile(),
          'line' => $e->getLine(),
          'trace' => $e->getTraceAsString()
        ]
      );

      Log::channel('siteCorretores')->error('Erro crítico no comando atualizarSites: ' . $e->getMessage());
      $this->error('Erro crítico no comando. Verifique os logs.');
      return 1;
    }
  }

  private function desativarSitesSemPlano()
  {
    // $this->info('Iniciando processo de desativação de sites sem plano ativo...');

    try {
      $SiteCorretorDominiosController = app(SiteCorretorDominiosController::class);
      $usersWithoutActivePlan = $SiteCorretorDominiosController->getUsersWithoutActivePlanWithDomain();

      if (!empty($usersWithoutActivePlan)) {
        try {
          $response = Http::post('http://consultoraimobiliaria.com.br/api/atualizar-sites', [
            'user_ids' => $usersWithoutActivePlan,
          ]);

          if ($response->successful()) {
            // Logs removidos
          } else {
            Log::channel('siteCorretores')->error('Erro na resposta da API atualizar-sites', [
              'status' => $response->status(),
              'body' => $response->body(),
            ]);
          }
        } catch (\Exception $e) {
          DiscordLogService::logServiceError(
            'AtualizarSites@desativarSitesSemPlano',
            'Erro ao desativar site de usuários inadimplentes: ' . $e->getMessage(),
            [
              'user_ids' => $usersWithoutActivePlan,
              'command' => 'atualizarSites',
              'file' => $e->getFile(),
              'line' => $e->getLine(),
              'trace' => $e->getTraceAsString()
            ]
          );

          Log::channel('siteCorretores')->error('Erro ao desativar site: ' . $e->getMessage(), [
            'exception' => $e,
          ]);
        }
      }
    } catch (\Exception $e) {
      $this->error('Erro ao processar desativação de sites: ' . $e->getMessage());
      throw $e;
    }
  }

  private function ativarSitesComPlano()
  {
    // $this->info('Iniciando processo de ativação de sites com plano ativo...');

    try {
      $SiteCorretorDominiosController = app(SiteCorretorDominiosController::class);
      $usersWithActivePlan = $SiteCorretorDominiosController->getUsersWithActivePlanWithDomain();

      if (!empty($usersWithActivePlan)) {
        try {
          $response = Http::post('http://consultoraimobiliaria.com.br/api/atualizar-sites', [
            'user_ids' => $usersWithActivePlan,
          ]);

          if ($response->successful()) {
            // Logs removidos
          } else {
            $this->error(sprintf('Falha ao ativar sites. HTTP Status: %d. Resposta: %s', $response->status(), $response->body())
            );
          }
        } catch (\Exception $e) {
          DiscordLogService::logServiceError(
            'AtualizarSites@ativarSitesComPlano',
            'Erro ao ativar site de usuários em dia: ' . $e->getMessage(),
            [
              'user_ids' => $usersWithActivePlan,
              'command' => 'atualizarSites',
              'file' => $e->getFile(),
              'line' => $e->getLine(),
              'trace' => $e->getTraceAsString()
            ]
          );

          Log::channel('siteCorretores')->error('Erro ao ativar site: ' . $e->getMessage(), [
            'exception' => $e,
          ]);
          $this->error('Erro ao ativar site.');
        }
      }
    } catch (\Exception $e) {
      $this->error('Erro ao processar ativação de sites: ' . $e->getMessage());
      throw $e;
    }
  }
}