<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ConsultationAggregateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $consultations = [
            ['type' => 'consultation1', 'table' => 'consultation_log', 'date_field' => 'created_at'],
            ['type' => 'consultation1_email', 'table' => 'consultation1_log_email', 'date_field' => 'created_at'],
            ['type' => 'consultation2', 'table' => 'consultation2_log', 'date_field' => 'created_at'],
            ['type' => 'consultation3', 'table' => 'consultation3_log', 'date_field' => 'created_at'],
            ['type' => 'consultation4', 'table' => 'consultation4_log', 'date_field' => 'created_at'],
            ['type' => 'consultation5', 'table' => 'consultation5_log', 'date_field' => 'created_at'],
            ['type' => 'consultation6', 'table' => 'consultation6_log', 'date_field' => 'created_at'],
            ['type' => 'consultation12', 'table' => 'consultation12_log', 'date_field' => 'created_at'],
        ];

        $periods = [
            'today' => [Carbon::today(), Carbon::tomorrow()],
            'yesterday' => [Carbon::yesterday(), Carbon::today()],
            '7d' => [Carbon::today()->subDays(7), Carbon::tomorrow()],
            '30d' => [Carbon::today()->subDays(30), Carbon::tomorrow()],
            '60d' => [Carbon::today()->subDays(60), Carbon::tomorrow()],
            '90d' => [Carbon::today()->subDays(90), Carbon::tomorrow()],
            '120d' => [Carbon::today()->subDays(120), Carbon::tomorrow()],
            '365d' => [Carbon::today()->subDays(365), Carbon::tomorrow()],
        ];

        foreach ($consultations as $consultation) {
            foreach ($periods as $period => [$start, $end]) {
                $count = DB::table($consultation['table'])
                    ->where($consultation['date_field'], '>=', $start)
                    ->where($consultation['date_field'], '<', $end)
                    ->count();

                DB::table('consultation_aggregates')->updateOrInsert(
                    [
                        'consultation_type' => $consultation['type'],
                        'date' => Carbon::today()->toDateString(),
                        'period' => $period,
                    ],
                    [
                        'count' => $count,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
        // Limpa o cache relacionado
        Cache::forget('consultation_reports');
    }
}
