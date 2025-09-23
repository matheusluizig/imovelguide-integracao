<?php

namespace App\Integracao\Domain\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;

class IntegrationsQueues extends Model
{
    use HasFactory;

    protected $table = 'integrations_queues';
    protected $fillable = [
        'integration_id', 'priority', 'status', 'started_at', 'ended_at',
        'created_at', 'updated_at', 'error_message', 'attempts',
        'completed_at', 'execution_time', 'last_error_step', 'error_details'
    ];
    protected $attributes = ['started_at' => NULL, 'ended_at' => NULL];
    protected $casts = [
        'error_details' => 'json',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    public const NECESSARY_PRIORITY_LEVEL = 6;
    public const PRIORITY_NORMAL = 0, PRIORITY_LEVEL = 1, PRIORITY_PLAN = 2;
    public const STATUS_PENDING = 0, STATUS_IN_PROCESS = 1, STATUS_DONE = 2, STATUS_STOPPED = 3, STATUS_ERROR = 4;


    private const STATUS_TO_STR = [
        self::STATUS_PENDING => 'Pendente.',
        self::STATUS_IN_PROCESS => 'Sendo feita.',
        self::STATUS_DONE => 'Finalizada',
        self::STATUS_STOPPED => 'Parada pelo sistema.',
        self::STATUS_ERROR => 'Com erro.'
    ];

    public function integration()
    {
        return $this->hasOne('App\Integracao\Domain\Entities\Integracao', 'id', 'integration_id');
    }

    public function integracaoXml()
    {
        return $this->belongsTo('App\Integracao\Domain\Entities\Integracao', 'integration_id', 'id');
    }

    public function getStatusStrAttribute()
    {
        return self::STATUS_TO_STR[$this->status];
    }
}