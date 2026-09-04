<?php

namespace App\Livewire\OnePloy;

use App\Services\OnePloy\EntitlementResolver;
use App\Services\OnePloy\TeamResourceUsage;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Usage extends Component
{
    public function render(EntitlementResolver $entitlements, TeamResourceUsage $usage): View
    {
        $team = currentTeam();

        return view('livewire.oneploy.usage', [
            'quotas' => collect(TeamResourceUsage::MEASURABLE_QUOTA_KEYS)->mapWithKeys(function (string $key) use ($team, $entitlements, $usage) {
                return [$key => [
                    'limit' => $team ? $entitlements->limit($team, $key) : 0,
                    'used' => $team ? $usage->for($team, $key) : 0,
                ]];
            }),
        ]);
    }
}
