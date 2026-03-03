<?php

namespace App\Models;

use App\Models\Concerns\HasModelUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class SwotAnalysis extends Model
{
    use HasFactory;
    use HasModelUuid;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'customer_uuid',
        'trend_analysis_run_id',
        'status',
        'analysis_title',
        'analysis_summary',
        'brain_conversation_id',
        'filters',
        'raw_ai_payload',
        'generated_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'raw_ai_payload' => 'array',
        'generated_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function cards(): HasMany
    {
        return $this->hasMany(SwotCard::class, 'analysis_id');
    }

    public function sourceGovernances(): HasMany
    {
        return $this->hasMany(SwotSourceGovernance::class, 'analysis_id');
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(
            SwotCardItem::class,
            SwotCard::class,
            'analysis_id',
            'card_id',
            'id',
            'id'
        );
    }
}
