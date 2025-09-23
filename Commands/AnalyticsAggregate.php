<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\Cache;

class AnalyticsAggregate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:aggregate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Agrega os dados analíticos em métricas diárias, semanais e mensais';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (DB::table('analytics')->count() === 0) {
            return;
        }

        try {
            $this->dailyAccesses();
            $this->weeklyAccesses();
            $this->monthlyAccesses();
        } catch (Exception $e) {
            $this->error('Erro durante a agregação: ' . $e->getMessage());
            Log::error('Erro durante a agregação: ' . $e->getMessage());
        }
    }

    public function dailyAccesses()
    {
        try {
            // Cache diário 5 min: soma dos valores na coluna 'access' para o dia atual
            $dailyAccesses = Cache::remember('daily_accesses_' . now()->toDateString(), 300, function () {
                $result = DB::table('analytics')
                    ->whereDate('created_at', now()->toDateString())
                    ->sum('access');
                return $result;
            });


            if ($dailyAccesses > 0) {
            } else {
                return;
            }

            // Inserir ou atualizar na tabela analytics_aggregated
            DB::table('analytics_aggregated')->updateOrInsert(
        ['day' => now()->toDateString(), 'metric_type' => 'access_count'],
            ['value' => $dailyAccesses, 'created_at'=> now(), 'updated_at' => now()]
            );
        } catch (Exception $e) {
            $this->error('Erro ao calcular acessos diários: ' . $e->getMessage());
            Log::error('Erro ao calcular acessos diários: ' . $e->getMessage());
        }
    }

    public function weeklyAccesses()
    {
        try {
            // Cache semanal de 1 hora: soma dos valores na coluna 'access' para a semana atual
            $weeklyAccesses = Cache::remember('weekly_accesses_' . now()->startOfWeek()->toDateString() , 60 * 60, function () {
                $result = DB::table('analytics')
                    ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                    ->sum('access'); 
                return $result;
            });

            if ($weeklyAccesses > 0) {
            } else {
                return;
            }

            // Inserir ou atualizar na tabela analytics_aggregated
            DB::table('analytics_aggregated')->updateOrInsert(
                ['week' => now()->startOfWeek()->toDateString(), 'metric_type' => 'access_count'],
                ['value' => $weeklyAccesses, 'created_at'=> now(), 'updated_at' => now()]
            );
        } catch (Exception $e) {
            $this->error('Erro ao calcular acessos semanais: ' . $e->getMessage());
            Log::error('Erro ao calcular acessos semanais: ' . $e->getMessage());
            return;
        }
    }

    public function monthlyAccesses()
    {
        try {
            // Cache mensal 6 horas: soma dos valores na coluna 'access' para o mês atual
            $monthlyAccesses = Cache::remember('monthly_accesses_' . now()->format('Y-m'), 60 * 60 * 6, function () {
                $result = DB::table('analytics')
                    ->whereMonth('created_at', now()->month)
                    ->sum('access');
                return $result;
            });

            if ($monthlyAccesses > 0) {
            } else {
                return;
            }

            // Inserir ou atualizar na tabela analytics_aggregated
            DB::table('analytics_aggregated')->updateOrInsert(
                ['month' => now()->format('Y-m'), 'metric_type' => 'access_count'],
                ['value' => $monthlyAccesses, 'created_at' => now(), 'updated_at' => now()]
            );

        } catch (Exception $e) {
            $this->error('Erro ao calcular acessos mensais: ' . $e->getMessage());
            Log::error('Erro ao calcular acessos mensais: ' . $e->getMessage());
        }
    }
}
