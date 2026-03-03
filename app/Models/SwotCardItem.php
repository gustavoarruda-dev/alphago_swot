<?php

namespace App\Models;

use App\Models\Concerns\HasModelUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SwotCardItem extends Model
{
    use HasFactory;
    use HasModelUuid;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'card_id',
        'item_key',
        'title',
        'description',
        'tag',
        'priority',
        'period',
        'kpi',
        'owner',
        'swot_link',
        'impact',
        'dimension',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(SwotCard::class, 'card_id');
    }
}
