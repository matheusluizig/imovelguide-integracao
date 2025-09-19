<?php

namespace App\Integracao\Domain\Entities;

use App\Crm;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Integracao extends Model
{
    protected $table = 'integracao_xml';
    protected $attributes = ['status' => 0];
    protected $dates = ['created_at', 'updated_at'];
    protected $guarded = [];

    public const XML_STATUS_NOT_INTEGRATED = 0;
    public const XML_STATUS_IGNORED = 1;
    public const XML_STATUS_INTEGRATED = 2;
    public const XML_STATUS_IN_ANALYSIS = 3;
    public const XML_STATUS_CODE49 = 4;
    public const XML_STATUS_INATIVO = 5;
    public const XML_STATUS_IN_UPDATE_BOTH = 6;
    public const XML_STATUS_IN_DATA_UPDATE = 7;
    public const XML_STATUS_IN_IMAGE_UPDATE = 8;
    public const XML_STATUS_PROGRAMMERS_SOLVE = 9;
    public const XML_STATUS_LINKS_NOT_WORKING = 10;
    public const XML_STATUS_CRM_ERRO = 11;
    public const XML_STATUS_WRONG_MODEL = 12;
    public const USER_ACTIVE = 0;
    public const USER_INACTIVE = 1;

    private const STATUS_TO_STR = [
        self::XML_STATUS_NOT_INTEGRATED => 'XML Não Integrado',
        self::XML_STATUS_IGNORED => 'XML Removido',
        self::XML_STATUS_INTEGRATED => 'XML Integrado',
        self::XML_STATUS_IN_ANALYSIS => 'XML em Análise',
        self::XML_STATUS_INATIVO =>  'XML Integrado User Inativo',
        self::XML_STATUS_IN_UPDATE_BOTH =>  'XML em Atualização Automática de Dados e Imagens',
        self::XML_STATUS_IN_DATA_UPDATE =>  'XML em Atualização Automática de Dados',
        self::XML_STATUS_IN_IMAGE_UPDATE =>  'XML em Atualização Automática de Imagens',
        self::XML_STATUS_PROGRAMMERS_SOLVE =>  'XML em Análise dos Programadores',
        self::XML_STATUS_LINKS_NOT_WORKING =>  'Link Não Funcional.',
        self::XML_STATUS_CRM_ERRO =>  'CRM Com Erro',
        self::XML_STATUS_WRONG_MODEL =>  'Modelo de XML Não Faz Parte dos Nossos Modelos'
    ];

    public function user()
    {
        return $this->belongsTo('App\User', 'user_id', 'id');
    }

    public function crm()
    {
        return $this->belongsTo('App\Crm', 'crm_id', 'id');
    }

    public function queue()
    {
        return $this->belongsTo('App\Integracao\Domain\Entities\IntegrationsQueues', 'id', 'integration_id');
    }

    public function getIntegrationStatusAttribute()
    {
        if ($this->user->inative == self::USER_INACTIVE) {
            return self::STATUS_TO_STR[self::XML_STATUS_INATIVO];
        }
        return self::STATUS_TO_STR[$this->status];
    }

    public function getIntegrationQueueAttribute()
    {
        $status = "Antiga";
        if ($this->queue) {
            if ($this->queue->status == IntegrationsQueues::STATUS_ERROR) {
                $status = "{$this->queue->status_str} (Contate os programadores)";
            } elseif ($this->status == self::XML_STATUS_IGNORED) {
                $status = 'Esse XML foi ignorado por um Admin.';
            } else {
                $status = $this->queue->status_str;
            }
        } elseif ($this->status == self::XML_STATUS_IGNORED) {
            $status = 'Esse XML foi ignorado por um Admin.';
        }

        return $status;
    }

    public static function getCountByStatus($result, $statusToCheck, $userUstatus = self::USER_ACTIVE) {
        return $result->filter(function($value, $key) use($statusToCheck, $userUstatus) {
            return $value->status == $statusToCheck && $value->user->inative == $userUstatus;
        });
    }

    public static function getIntegracoesSemCrms($result) {
        return $result->filter(function($value, $key) {
            return $value->crm == NULL;
        });
    }

    public static function getIntegracoesToday($result) {
        return $result->filter(function($value, $key) {
            return $value->created_at >= Carbon::today();
        })->count();
    }
    public static function getUserHasPlan($result) {
        $statusArray = [
            Integracao::XML_STATUS_INTEGRATED,
            Integracao::XML_STATUS_LINKS_NOT_WORKING,
            Integracao::XML_STATUS_CRM_ERRO,
            Integracao::XML_STATUS_NOT_INTEGRATED,
            Integracao::XML_STATUS_IN_ANALYSIS,
            
        ];
        return $result->filter(function ($value, $key) use ($statusArray) {
            if ($value->user->level >= 6 || ($value->user->asaasSub !== null && $value->user->asaasSub->status)) {
                $next_update = ($value->updated_at ? $value->updated_at->addDay(1) : null);
            } else {
                $next_update = ($value->updated_at ? $value->updated_at->addDay(4) : null);
            }
        
            return in_array($value->status, $statusArray) &&
                $value->user->asaasSub !== null &&
                $value->user->asaasSub->status === 1 &&
                ($value->user->asaasSub->data_cancelamento === null || $value->user->asaasSub->data_cancelamento > today()) &&
                ($next_update !== null && Carbon::now() > Carbon::parse($next_update->format('Y-m-d H:i:s')));
        });
        
    }
    public static function getXmlNaoAtualizado($result) {
        return $result->filter(function ($value, $key)  {
            if ($value->user->level >= 6 || ($value->user->asaasSub !== null && $value->user->asaasSub->status)) {
                $next_update = ($value->updated_at ? $value->updated_at->addDay(1) : null);
            } else {
                $next_update = ($value->updated_at ? $value->updated_at->addDay(4) : null);
            }
            return ($next_update !== null && Carbon::now() > Carbon::parse($next_update->format('Y-m-d H:i:s')));
        });
    }

    public static function getIntegracoesYesterday($result) {
        return $result->filter(function($value, $key) {
            return $value->created_at >= Carbon::yesterday() && $value->created_at <= Carbon::today();
        })->count();
    }

    public static function getIntegracoesByDays($result, $days) {
        return $result->filter(function($value, $key) use($days) {
            return $value->created_at >= Carbon::today()->subDay($days) && $value->created_at <= Carbon::today();
        })->count();
    }

    public static function getIntegracoesData($result) {
        $today = self::getIntegracoesToday($result);
        $yesterday = self::getIntegracoesYesterday($result);

        $lastWeek = self::getIntegracoesByDays($result, 7);
        $lastMonth = self::getIntegracoesByDays($result, 30);
        $monthBeforeLast = self::getIntegracoesByDays($result, 60);
        $trimester = self::getIntegracoesByDays($result, 90);
        $quarter = self::getIntegracoesByDays($result, 120);
        $annual = self::getIntegracoesByDays($result, 365);

        $averageWeek = $lastWeek . ' / ' . number_format($lastWeek / 7, 1);
        $averageMonth = $lastMonth . ' / ' . number_format($lastMonth / 30, 1);
        $averageMonthBeforeLast = $monthBeforeLast . ' / ' . number_format($monthBeforeLast / 60, 1);
        $trimesterly = $trimester . ' / ' . number_format($trimester / 90, 1);
        $quarterly = $quarter . ' / ' . number_format($quarter / 120, 1);
        $yearly = $annual . ' / ' . number_format($annual / 365, 1);

        return compact(
            'today', 'yesterday', 'lastWeek', 'lastMonth', 'monthBeforeLast', 'trimester', 'quarter',
            'averageWeek', 'averageMonth', 'averageMonthBeforeLast', 'trimesterly', 'quarterly', 'yearly'
		);
    }

    public function getCrmNameAttribute() {
        return $this->crm ? $this->crm->crm_name : "";
    }

    public function getCreatedAtBrAttribute() {
        return $this->created_at->format('d/m/Y H:i');
    }

    public function getUpdatedAtBrAttribute() {
        return $this->updated_at->format('d/m/Y H:i');
    }

    public function getNextUpdateBrAttribute() {
        return $this->updated_at->addDay($this->user->level >= 6 ? 1 : 4)->format('d/m/Y');
    }
}