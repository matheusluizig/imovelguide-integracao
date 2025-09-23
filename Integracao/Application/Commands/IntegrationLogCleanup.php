<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class IntegrationLogCleanup extends Command
{
    protected $signature = 'integration:log-cleanup 
                            {--dry-run : Apenas simular, não deletar arquivos}
                            {--days=30 : Manter logs dos últimos N dias}
                            {--compress : Comprimir logs antigos antes de deletar}';

    protected $description = 'Limpa e organiza logs antigos do sistema de integração';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $days = (int) $this->option('days');
        $compress = $this->option('compress');
        
        $cutoffDate = now()->subDays($days);
        
        $this->info("🧹 Limpeza de Logs de Integração");
        $this->line('═══════════════════════════════════════════════');
        
        if ($dryRun) {
            $this->warn("🔍 MODO DRY-RUN: Nenhum arquivo será deletado");
        }
        
        $this->line("📅 Removendo logs anteriores a: {$cutoffDate->format('Y-m-d H:i:s')}");
        $this->line("📊 Mantendo logs dos últimos: {$days} dias");
        $this->newLine();

        $logPath = storage_path('logs');
        $integrationLogs = [
            'integration.log',
            'integration-errors.log', 
            'integration-performance.log',
            'integration-system.log',
            'worker.log'
        ];

        $totalFiles = 0;
        $totalSize = 0;
        $deletedFiles = 0;
        $compressedFiles = 0;

        foreach ($integrationLogs as $logFile) {
            $this->processLogFile($logPath, $logFile, $cutoffDate, $dryRun, $compress, 
                $totalFiles, $totalSize, $deletedFiles, $compressedFiles);
        }

        
        $this->processLaravelLogs($logPath, $cutoffDate, $dryRun, $compress, 
            $totalFiles, $totalSize, $deletedFiles, $compressedFiles);

        $this->newLine();
        $this->line('📊 Resumo da Limpeza:');
        $this->line("   Arquivos analisados: {$totalFiles}");
        $this->line("   Tamanho total: " . $this->formatBytes($totalSize));
        
        if ($dryRun) {
            $this->line("   Arquivos que seriam deletados: {$deletedFiles}");
            $this->line("   Arquivos que seriam comprimidos: {$compressedFiles}");
        } else {
            $this->line("   Arquivos deletados: {$deletedFiles}");
            $this->line("   Arquivos comprimidos: {$compressedFiles}");
        }

        
        if (!$dryRun && ($deletedFiles > 0 || $compressedFiles > 0)) {
            $this->newLine();
            $this->info("✅ Limpeza concluída com sucesso!");
            
            
            $this->showDiskUsage($logPath);
        }

        return 0;
    }

    private function processLogFile(string $logPath, string $logFile, Carbon $cutoffDate, 
        bool $dryRun, bool $compress, int &$totalFiles, int &$totalSize, 
        int &$deletedFiles, int &$compressedFiles): void
    {
        $filePath = $logPath . '/' . $logFile;
        
        if (!File::exists($filePath)) {
            return;
        }

        $fileTime = Carbon::createFromTimestamp(File::lastModified($filePath));
        $fileSize = File::size($filePath);
        
        $totalFiles++;
        $totalSize += $fileSize;

        if ($fileTime->lt($cutoffDate)) {
            if ($compress && $fileSize > 1024 * 1024) { 
                $this->compressLogFile($filePath, $dryRun);
                $compressedFiles++;
                $this->line("🗜️  Comprimido: {$logFile} (" . $this->formatBytes($fileSize) . ")");
            } else {
                if (!$dryRun) {
                    File::delete($filePath);
                }
                $deletedFiles++;
                $this->line("🗑️  Deletado: {$logFile} (" . $this->formatBytes($fileSize) . ")");
            }
        } else {
            $this->line("✅ Mantido: {$logFile} (" . $this->formatBytes($fileSize) . ")");
        }
    }

    private function processLaravelLogs(string $logPath, Carbon $cutoffDate, bool $dryRun, 
        bool $compress, int &$totalFiles, int &$totalSize, 
        int &$deletedFiles, int &$compressedFiles): void
    {
        $pattern = $logPath . '/laravel-*.log';
        $files = glob($pattern);
        
        foreach ($files as $file) {
            $fileName = basename($file);
            
            
            if ($this->containsIntegrationLogs($file)) {
                $fileTime = Carbon::createFromTimestamp(File::lastModified($file));
                $fileSize = File::size($file);
                
                $totalFiles++;
                $totalSize += $fileSize;

                if ($fileTime->lt($cutoffDate)) {
                    if ($compress && $fileSize > 1024 * 1024) { 
                        $this->compressLogFile($file, $dryRun);
                        $compressedFiles++;
                        $this->line("🗜️  Comprimido: {$fileName} (" . $this->formatBytes($fileSize) . ")");
                    } else {
                        if (!$dryRun) {
                            File::delete($file);
                        }
                        $deletedFiles++;
                        $this->line("🗑️  Deletado: {$fileName} (" . $this->formatBytes($fileSize) . ")");
                    }
                } else {
                    $this->line("✅ Mantido: {$fileName} (" . $this->formatBytes($fileSize) . ")");
                }
            }
        }
    }

    private function containsIntegrationLogs(string $filePath): bool
    {
        try {
            $content = File::get($filePath);
            $integrationKeywords = [
                'Integration started',
                'Integration completed',
                'Integration failed',
                'XML processing',
                'ProcessIntegrationJob',
                'integration_id'
            ];

            foreach ($integrationKeywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            
        }

        return false;
    }

    private function compressLogFile(string $filePath, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        try {
            $compressedPath = $filePath . '.gz';
            
            
            $fp_out = gzopen($compressedPath, 'wb9');
            $fp_in = fopen($filePath, 'rb');
            
            while (!feof($fp_in)) {
                gzwrite($fp_out, fread($fp_in, 1024 * 512));
            }
            
            fclose($fp_in);
            gzclose($fp_out);
            
            
            File::delete($filePath);
            
        } catch (\Exception $e) {
            $this->error("Erro ao comprimir arquivo {$filePath}: " . $e->getMessage());
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function showDiskUsage(string $logPath): void
    {
        try {
            $totalSpace = disk_total_space($logPath);
            $freeSpace = disk_free_space($logPath);
            $usedSpace = $totalSpace - $freeSpace;
            
            $this->line('💾 Espaço em Disco:');
            $this->line("   Total: " . $this->formatBytes($totalSpace));
            $this->line("   Usado: " . $this->formatBytes($usedSpace));
            $this->line("   Livre: " . $this->formatBytes($freeSpace));
            $this->line("   Uso: " . round(($usedSpace / $totalSpace) * 100, 2) . "%");
            
        } catch (\Exception $e) {
            $this->warn("Não foi possível obter informações de espaço em disco");
        }
    }
}
