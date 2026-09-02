<?php

namespace App\Actions\Team;

use App\Models\Reseller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CreateTenant
{
    use AsAction;

    public function handle(User $owner, string $name, ?string $description = null): Team
    {
        Gate::forUser($owner)->authorize('create', Team::class);

        return DB::transaction(function () use ($owner, $name, $description): Team {
            $reseller = Reseller::query()
                ->where('owner_id', $owner->id)
                ->lockForUpdate()
                ->first();

            if ($reseller && ! $reseller->isActive()) {
                throw new AuthorizationException('Suspended resellers cannot create tenants.');
            }

            if ($reseller && ! $reseller->hasTenantCapacity()) {
                throw new RuntimeException('The reseller tenant limit has been reached.');
            }

            $plan = $reseller?->default_plan;
            if (filled($plan) && ! array_key_exists($plan, config('tenancy.plans', []))) {
                throw new RuntimeException("The reseller default plan [{$plan}] is not configured.");
            }

            $team = new Team;
            $team->fill([
                'name' => $name,
                'description' => $description,
                'personal_team' => false,
                'is_mcp_server_enabled' => true,
            ]);
            $team->forceFill([
                'reseller_id' => $reseller?->id,
                'plan' => $plan,
            ]);
            $team->save();

            $owner->teams()->attach($team, ['role' => 'owner']);

            DB::afterCommit(function () use ($owner, $reseller, $team): void {
                auditLog('tenant.created', [
                    'actor_id' => $owner->id,
                    'reseller_id' => $reseller?->id,
                    'tenant_id' => $team->id,
                ]);
            });

            return $team;
        }, 3);
    }
}
