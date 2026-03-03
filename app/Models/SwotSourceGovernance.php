<?php

namespace App\Models;

use App\Models\Concerns\HasModelUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SwotSourceGovernance extends Model
{
    use HasFactory;
    use HasModelUuid;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'analysis_id',
        'customer_uuid',
        'analysis_run_id',
        'source_name',
        'source_key',
        'source_origin',
        'source_url',
        'source_category',
        'status',
        'is_priority',
        'extra_metadata',
        'last_seen_at',
    ];

    protected $casts = [
        'extra_metadata' => 'array',
        'is_priority' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(SwotAnalysis::class, 'analysis_id');
    }
}
