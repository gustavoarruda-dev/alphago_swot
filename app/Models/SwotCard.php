<?php

namespace App\Models;

use App\Models\Concerns\HasModelUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SwotCard extends Model
{
    use HasFactory;
    use HasModelUuid;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'analysis_id',
        'card_key',
        'card_group',
        'title',
        'subtitle',
        'sort_order',
        'is_editable',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_editable' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(SwotAnalysis::class, 'analysis_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SwotCardItem::class, 'card_id');
    }
}
