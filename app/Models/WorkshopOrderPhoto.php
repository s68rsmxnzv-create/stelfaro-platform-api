<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'workshop_order_id', 'disk', 'path', 'original_name', 'mime_type', 'size', 'sha256', 'stage', 'uploaded_by', 'uploader_ip'])]
class WorkshopOrderPhoto extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkshopOrder::class, 'workshop_order_id');
    }
}
