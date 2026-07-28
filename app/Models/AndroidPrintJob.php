<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'agent_id', 'created_by', 'status', 'print_payload', 'processing_at', 'printed_at', 'last_error'])]
class AndroidPrintJob extends Model
{
    protected function casts(): array
    {
        return [
            'print_payload' => 'array',
            'processing_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }
}
