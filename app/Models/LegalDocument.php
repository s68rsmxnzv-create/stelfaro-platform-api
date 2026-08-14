<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'version', 'title', 'effective_at', 'public_path', 'content_hash', 'content_snapshot'])]
class LegalDocument extends Model
{
    protected function casts(): array
    {
        return [
            'effective_at' => 'date',
        ];
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }
}
