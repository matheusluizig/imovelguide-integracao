<?php

namespace App\Integracao\Application\Services;

use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Infrastructure\Parsers\XMLIntegrationsFactory;
use Illuminate\Support\Facades\Log;
use App\Services\DiscordLogService;

class XMLIntegrationLoggerService
{
  private $integration = null;

  public function __construct(Integracao $integration)
  {
    $this->integration = $integration;
  }

  public function loggerErrWarn(string $problem)
  {
    
    Log::warning("🔧 PROBLEMA NA INTEGRAÇÃO: {$problem}", [
      'integration_id' => $this->integration->id ?? 'unknown',
      'integration_name' => $this->integration->system ?? 'unknown',
      'problem' => $problem,
      'timestamp' => now()->format('Y-m-d H:i:s')
    ]);

    
    

    
    $businessCriticalErrors = [
      'Falha ao inserir anúncio',
      'Falha ao atualizar anúncio',
      'Falha ao processar XML',
      'Limite de anúncios excedido',
      'Usuário sem plano ativo',
      'Erro de autenticação no XML',
    ];

    
    $paymentCriticalErrors = [
      'Falha ao criar cobrança',
      'Falha ao atualizar cobrança',
      'Falha ao processar pagamento',
      'Falha ao ativar plano',
      'Falha ao cancelar plano',
    ];

    
    foreach ($businessCriticalErrors as $criticalError) {
      if (strpos($problem, $criticalError) !== false) {
        DiscordLogService::logBusinessCriticalError(
          'Integração XML - Falha Crítica',
          $problem,
          [
            'classe' => __CLASS__,
            'método' => __FUNCTION__,
            'nome_usuario' => $this->integration->user->name,
            'id_usuario' => $this->integration->user->id,
            'link_xml' => $this->integration->link,
            'id_integracao' => $this->integration->id,
            'status_atual' => $this->integration->status,
            'tipo_integracao' => $this->integration->type ?? 'não especificado',
            'ultima_atualizacao' => $this->integration->updated_at ?? 'não especificado',
            'tentativas' => $this->integration->attempts ?? 0,
          ]
        );
        return;
      }
    }

    
    foreach ($paymentCriticalErrors as $paymentError) {
      if (strpos($problem, $paymentError) !== false) {
        DiscordLogService::logPaymentError(
          'Integração XML - Erro de Pagamento',
          $problem,
          [
            'classe' => __CLASS__,
            'método' => __FUNCTION__,
            'nome_usuario' => $this->integration->user->name,
            'id_usuario' => $this->integration->user->id,
            'link_xml' => $this->integration->link,
            'id_integracao' => $this->integration->id,
            'status_atual' => $this->integration->status,
            'tipo_integracao' => $this->integration->type ?? 'não especificado',
          ]
        );
        return;
      }
    }

    
  }

  public function loggerDone(int $total, int $countDone, string $problems = '')
  {
    $status = $total == $countDone ? 'Completo' : 'Parcialmente';
    $toLog = [
      'status' => $status,
      'id_integracao' => $this->integration->id,
      'total_imoveis' => $total,
      'total_processados' => $countDone,
      'taxa_sucesso' => $total > 0 ? round(($countDone / $total) * 100, 2) . '%' : '0%',
      'nome_usuario' => $this->integration->user->name,
      'id_usuario' => $this->integration->user->id,
      'link_xml' => $this->integration->link,
      'data_hora' => now()->format('Y-m-d H:i:s'),
      'tipo_integracao' => $this->integration->type ?? 'não especificado',
      'status_integracao' => $this->integration->status ?? 'não especificado',
      'ultima_atualizacao' => $this->integration->updated_at ?? 'não especificado',
      'tentativas' => $this->integration->attempts ?? 0,
    ];

    if ($problems != '') {
      $toLog['problemas'] = $problems;
    }

    
  }
}
