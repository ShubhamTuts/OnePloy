<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneployCommerceSubscription extends Model
{
    protected $table = 'oneploy_commerce_subscriptions';

    public const GRACE_STATUSES = [
        'trialing',
        'past_due',
        'grace',
        'grace_period',
    ];

    protected $fillable = [
        'team_id',
        'product_id',
        'plan_version_id',
        'price_id',
        'status',
        'legacy_stripe_subscription_id',
        'legacy_stripe_price_id',
        'current_period_ends_at',
        'grace_ends_at',
        'entitlement_snapshot',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'entitlement_snapshot' => 'array',
            'current_period_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
        ];
    }

    public function scopeForTeam(Builder $query, Team|int $team): Builder
    {
        return $query->where('team_id', $team instanceof Team ? $team->getKey() : $team);
    }

    public function scopeForProductFamily(Builder $query, string $family): Builder
    {
        return $query->whereHas('product', function (Builder $productQuery) use ($family): void {
            $productQuery->where('family', $family);
        });
    }

    public function scopeEligible(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $eligibilityQuery) use ($at): void {
            $eligibilityQuery
                ->where(function (Builder $activeQuery) use ($at): void {
                    $activeQuery
                        ->where('status', 'active')
                        ->where(function (Builder $periodQuery) use ($at): void {
                            $periodQuery
                                ->whereNull('current_period_ends_at')
                                ->orWhere('current_period_ends_at', '>', $at);
                        });
                })
                ->orWhere(function (Builder $graceQuery) use ($at): void {
                    $graceQuery
                        ->whereIn('status', self::GRACE_STATUSES)
                        ->whereNotNull('grace_ends_at')
                        ->where('grace_ends_at', '>', $at);
                });
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(OneployProduct::class, 'product_id');
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(OneployPlanVersion::class, 'plan_version_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(OneployPrice::class, 'price_id');
    }
}
