<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class OneployPrice extends Model
{
    protected $table = 'oneploy_prices';

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $price) {
            foreach (['plan_version_id', 'currency', 'interval', 'amount_minor'] as $attribute) {
                if ($price->isDirty($attribute)) {
                    throw new RuntimeException('Persisted prices are immutable. Create a new price instead.');
                }
            }
        });
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(OneployPlanVersion::class, 'plan_version_id');
    }

    public function scopeEffectiveAt(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_from')
                ->orWhere('effective_from', '<=', $at))
            ->where(fn (Builder $query) => $query
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $at));
    }

    public function formatted(): string
    {
        return strtoupper($this->currency).' '.number_format($this->amount_minor / 100, 2);
    }
}
