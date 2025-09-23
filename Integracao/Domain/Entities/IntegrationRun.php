<?php

namespace App\Integracao\Domain\Entities;

use Illuminate\Database\Eloquent\Model;

class IntegrationRun extends Model
{
    protected $fillable = [
        'integration_id', 'user_id', 'total_items', 'processed_items', 'status', 'error_message'
    ];

    public function chunks()
    {
        return $this->hasMany(IntegrationRunChunk::class, 'run_id');
    }
}


