<?php

namespace App\Integracao\Domain\Entities;

use Illuminate\Database\Eloquent\Model;

class IntegrationRunChunk extends Model
{
    protected $fillable = [
        'run_id', 'offset', 'limit', 'processed', 'status', 'error_message'
    ];

    public function run()
    {
        return $this->belongsTo(IntegrationRun::class, 'run_id');
    }
}


