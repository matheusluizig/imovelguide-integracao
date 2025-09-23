<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AggregateAnuncioViews extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'anuncio:aggregate-views 
                            {--days-to-keep=7 : Número de dias de detalhe para manter}
                            {--dry-run : Executa sem fazer alterações}
                            {--force : Força a limpeza dos dados detalhados}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Agrega visualizações de anúncios em resumo diário e purga registros antigos';

  /**
   * Execute the console command.
   */
  public function handle(): int
  {
    $retentionDays = config('app.views.retention_days', 180); // 6 meses
    $cutoffDate = Carbon::now()->subDays($retentionDays)->format('Y-m-d');

    $this->info('🚀 Iniciando agregação e limpeza de dados de views...');
    $this->info("📅 Data de corte: {$cutoffDate} (mantendo {$retentionDays} dias = 6 meses)");
    $this->info("⚡ Sistema 100% automático - agregação e limpeza automáticas");

    try {
      // 1. Agregar anuncios_views
      $this->aggregateAnunciosViews($cutoffDate);

      // 2. Agregar anuncio_page_views (se necessário no futuro)
      $this->aggregatePageViews($cutoffDate);

      // 3. Mostrar estatísticas antes da limpeza
      $this->showCleanupStats($cutoffDate);

      // 4. Confirmar limpeza
      if ($this->option('force') || $this->confirm('Deseja prosseguir com a limpeza dos dados detalhados?')) {
        $this->cleanupOldData($cutoffDate);
      } else {
      }
    } catch (\Exception $e) {
      $this->error('❌ Erro durante o processo: ' . $e->getMessage());
      Log::error('Erro na agregação de views', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);
      return 1;
    }

    return 0;
  }

  private function aggregateAnunciosViews(string $cutoffDate): void
  {
    $sql = "
            INSERT INTO anuncios_views_daily (anuncio_id, dia, total_views, total_unique_users, created_at, updated_at)
            SELECT 
                anuncio_id,
                DATE(created_at) as dia,
                COUNT(*) as total_views,
                COUNT(DISTINCT user) as total_unique_users,
                NOW() as created_at,
                NOW() as updated_at
            FROM anuncios_views 
            WHERE DATE(created_at) < ?
            GROUP BY anuncio_id, DATE(created_at)
            ON DUPLICATE KEY UPDATE 
                total_views = VALUES(total_views),
                total_unique_users = VALUES(total_unique_users),
                updated_at = NOW()
        ";

    $result = DB::statement($sql, [$cutoffDate]);
    $affected = DB::getPdo()->lastInsertId() ?: 'N/A';
    $this->line('  ✅ Agregação anuncios_views concluída');
  }

  private function aggregatePageViews(string $cutoffDate): void
  {
    // Para page_views, apenas contar (já está na estrutura básica)
    $countSql = "
            SELECT COUNT(*) as total 
            FROM anuncio_page_views 
            WHERE DATE(created_at) < ?
        ";

    $count = DB::selectOne($countSql, [$cutoffDate]);
    $this->line("  📊 Registros de page_views anteriores a {$cutoffDate}: {$count->total}");
  }

  private function showCleanupStats(string $cutoffDate): void
  {
    // Contar registros a serem removidos
    $anunciosViewsCount = DB::selectOne(
      "
            SELECT COUNT(*) as total FROM anuncios_views WHERE DATE(created_at) < ?
        ",
      [$cutoffDate]
    )->total;

    $pageViewsCount = DB::selectOne(
      "
            SELECT COUNT(*) as total FROM anuncio_page_views WHERE DATE(created_at) < ?
        ",
      [$cutoffDate]
    )->total;

    $this->line('  📊 anuncios_views a remover: ' . number_format($anunciosViewsCount));
    $this->line('  📊 anuncio_page_views a remover: ' . number_format($pageViewsCount));
  }

  private function cleanupOldData(string $cutoffDate): void
  {
    // Contar registros a serem removidos
    $anunciosViewsCount = DB::selectOne('SELECT COUNT(*) as total FROM anuncios_views WHERE DATE(created_at) < ?', [
      $cutoffDate,
    ])->total;

    $pageViewsCount = DB::selectOne('SELECT COUNT(*) as total FROM anuncio_page_views WHERE DATE(created_at) < ?', [
      $cutoffDate,
    ])->total;

    // Purgar em lotes para evitar locks longos
    if ($anunciosViewsCount > 0) {
      $this->purgeBatches('anuncios_views', $cutoffDate, $anunciosViewsCount);
    }

    if ($pageViewsCount > 0) {
      $this->purgeBatches('anuncio_page_views', $cutoffDate, $pageViewsCount);
    }
  }

  private function purgeBatches(string $table, string $cutoffDate, int $totalCount): void
  {
    $batchSize = config('app.views.batch_size', 10000);
    $processed = 0;

    $this->line("  🗑️  Removendo {$table} em lotes de {$batchSize}...");

    $progressBar = $this->output->createProgressBar($totalCount);
    $progressBar->start();

    while ($processed < $totalCount) {
      $deleted = DB::table($table)
        ->whereRaw('DATE(created_at) < ?', [$cutoffDate])
        ->orderBy('id')
        ->limit($batchSize)
        ->delete();

      if ($deleted === 0) {
        break; // Não há mais registros
      }

      $processed += $deleted;
      $progressBar->advance($deleted);

      $sleepMicroseconds = $deleted > 5000 ? 200000 : 100000; // 0.2s ou 0.1s
      usleep($sleepMicroseconds);
    }

    $progressBar->finish();
    $this->line("\n  ✅ {$table}: " . number_format($processed) . ' registros removidos');
  }
}