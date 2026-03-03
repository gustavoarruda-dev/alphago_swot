<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasModelUuid
{
    protected static function bootHasModelUuid(): void
    {
        static::creating(function ($model): void {
            if (! isset($model->uuid) || ! is_string($model->uuid) || trim($model->uuid) === '') {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
