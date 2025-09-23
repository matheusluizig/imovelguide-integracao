<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\PlanController;
use Illuminate\Support\Facades\Log;

class AtualizarAssinaturas extends Command
{
  /**
   * O nome e a assinatura do comando do console.
   *
   * @var string
   */
  protected $signature = 'atualizarAssinaturas';

  /**
   * A descrição do comando do console.
   *
   * @var string
   */
  protected $description = 'Gerencia assinaturas: desativa devedores e ativa em dia';

  /**
   * Execute o comando do console.
   *
   * @return int
   */
  public function handle()
  {
    try {
      $this->desativarAssinaturas(30);
      $this->ativarAssinaturas(30);

      \Artisan::call('atualizarSites');

    } catch (\Exception $e) {
      $this->error("Erro ao executar o comando: {$e->getMessage()}");
      Log::channel('planosAtualizacoes')->error('Erro ao executar comando atualizarAssinaturas', [
        'erro' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);
    }
  }

  private function desativarAssinaturas($diasAtraso)
  {
    $this->info('Iniciando processo de desativação de assinaturas de devedores...');

    $planController = new PlanController();
    $resultado = $planController->desativarAssinaturasDevedores($diasAtraso);

    $this->info("Assinaturas desativadas: {$resultado['desativadas']}");

    if ($resultado['desativadas'] > 0 && !empty($resultado['detalhes'])) {
      $this->table(['user_id', 'assinatura_id', 'nome_plano', 'subscription_id'], $resultado['detalhes']);
      $assinaturaIds = array_column($resultado['detalhes'], 'assinatura_id');
      Log::channel('planosAtualizacoes')->info('IDs das assinaturas desativadas', [
          'assinatura_ids' => $assinaturaIds,
      ]);
    } else {
      $this->info('Nenhuma assinatura foi desativada.');
    }

    Log::channel('planosAtualizacoes')->info('Processo de desativação concluído', [
      'total_desativadas' => $resultado['desativadas'],
    ]);

    if ($resultado['desativadas'] > 0 && !empty($resultado['detalhes'])) {
      $this->table(['user_id', 'assinatura_id', 'nome_plano', 'subscription_id'], $resultado['detalhes']);
      $assinaturaIds = array_column($resultado['detalhes'], 'assinatura_id');
      Log::channel('planosAtualizacoes')->info('IDs das assinaturas desativadas', [
        'assinatura_ids' => $assinaturaIds,
      ]);
    } else {
      $this->info('Nenhuma assinatura foi desativada.');
    }

    Log::channel('planosAtualizacoes')->info('Processo de desativação concluído', [
      'total_desativadas' => $resultado['desativadas'],
    ]);
  }

  private function ativarAssinaturas($diasAtraso)
  {
    $this->info('Iniciando processo de ativação de assinaturas em dia...');

    $planController = new PlanController();
    $resultado = $planController->ativarAssinaturasEmDia($diasAtraso);

    $this->info("Assinaturas ativadas: {$resultado['ativadas']}");

    if ($resultado['ativadas'] > 0 && !empty($resultado['detalhes'])) {
      $this->table(['user_id', 'assinatura_id', 'nome_plano', 'subscription_id'], $resultado['detalhes']);
      $assinaturaIds = array_column($resultado['detalhes'], 'assinatura_id');
      Log::channel('planosAtualizacoes')->info('IDs das assinaturas ativadas', [
        'assinatura_ids' => $assinaturaIds,
      ]);
    } else {
      $this->info('Nenhuma assinatura foi ativada.');
    }

    Log::channel('planosAtualizacoes')->info('Processo de ativação concluído', [
      'total_ativadas' => $resultado['ativadas'],
    ]);
  }
}