<?php

namespace App\Services\OnePloy;

use App\Models\OneployQuotaReservation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TeamMemberAdmission
{
    public function __construct(private readonly QuotaGate $quotaGate) {}

    public function attach(User $user, Team $team, string $role): bool
    {
        return DB::transaction(function () use ($user, $team, $role): bool {
            $lockedTeam = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            if ($user->teams()->where('team_id', $lockedTeam->getKey())->exists()) {
                return false;
            }

            $reservation = $this->quotaGate->reserveIfLimited(
                team: $lockedTeam,
                quotaKey: 'members.max',
                idempotencyKey: "team-member:{$user->getKey()}",
            );

            $user->teams()->attach($lockedTeam->getKey(), ['role' => $role]);

            if ($reservation instanceof OneployQuotaReservation) {
                $this->quotaGate->consume($reservation, (int) $user->getKey());
            }

            return true;
        }, 3);
    }
}
