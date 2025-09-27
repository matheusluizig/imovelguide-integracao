<?php

namespace App\Integracao\Infrastructure\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Throwable

class IntegrationHelper {
    public static function getQueuesIntegration($sqlResult) {
        $jobs = [];
        foreach ($sqlResult as $job) {
            $payload = json_decode($job->payload);
            if ($payload->data->commandName == 'App\Jobs\ProcessIntegration') {
                try {
                    $integrationCommand = unserialize($payload->data->command);
                    $jobs[] = [
                        'integration' => $integrationCommand->integration,
                        'queue' => $integrationCommand->queue
                    ];
                } catch (\Throwable $th) {
                    DB::table(config('queue.connections.database.table'))->where('id', $job->id)->delete();
                }
            }
        }

        return $jobs;
    }

    public static function getJobsByQueue($queues, $jobType) {
        $jobs = [];
        foreach ($queues as $job) {
            if ($job['queue'] == $jobType) {
                $jobs[] = $job['integration'];
            }
        }

        return $jobs;
    }

    public static function getLinkInfo($link) {
        $info = ['fileType' => 'Não é XML', 'size' => 'Imossível mensurar.', 'code' => 0];
        if (empty($link)) {
            return $info;
        }

        $cacheKey = 'link_info_' . md5($link);
        if (cache()->has($cacheKey)) {
            return cache()->get($cacheKey);
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'User-Agent' => 'ImovelGuide/1.0 (XML Integration Service; +https://imovelguide.com.br)'
                ])
                ->get($link);
        } catch (\Throwable $th) {
            $response = null;
        }

        $statusCode = 0;
        if ($response) {
            $statusCode = $response->getStatusCode();
        }

        $info['code'] = $statusCode;
        if (!$statusCode || $statusCode !== 200) {
            $info['fileType'] = 'Link inválido.';
            cache()->put($cacheKey, $info, now()->addMinutes(30));
            return $info;
        }

        $fileType = $response->getHeader('Content-Type');
        if (stripos($fileType[0], 'application/xml') === false && stripos($fileType[0], 'text/xml') === false) {
            cache()->put($cacheKey, $info, now()->addMinutes(30));
            return $info;
        }

        $info['fileType'] = 'É XML';
        $fileSize = $response->getHeader('Content-Length');
        if (empty($fileSize)) {
            cache()->put($cacheKey, $info, now()->addMinutes(30));
            return $info;
        }

        if ($fileSize !== false) {
            $info['size'] = self::formatBytes(intval($fileSize[0]));
        }

        cache()->put($cacheKey, $info, now()->addMinutes(30));
        return $info;
    }

    public static function formatBytes($bytes) {
        if ($bytes > 0) {
            $i = floor(log($bytes) / log(1024));
            $sizes = array('Bytes', 'KBytes', 'MBytes', 'GBytes', 'TBytes', 'PBytes', 'EBytes', 'ZBytes', 'YBytes');
            return sprintf('%.02F', round($bytes / pow(1024, $i), 1)) * 1 . ' ' . @$sizes[$i];
        } else {
            return 0;
        }
    }

    public static function loadSafeLink($link) {
        $safeLink = str_replace(array('\r', '\n', '\t'), '', $link);
        $safeLink = trim(preg_replace('/\t+/', '', $safeLink));
        $safeLink = trim(preg_replace('/\n+/', '', $safeLink));
        $safeLink = trim(preg_replace('/\r+/', '', $safeLink));
        return preg_replace('/(\v|\s)+/', '', $safeLink);
    }
}