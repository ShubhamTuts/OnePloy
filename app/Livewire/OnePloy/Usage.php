<?php

namespace App\Livewire\OnePloy;

use App\Models\Team;
use Livewire\Component;

class Usage extends Component
{
    public function render()
    {
        $team = currentTeam();

        return view('livewire.oneploy.usage', [
            'quotas' => collect(config('tenancy.quotas'))->mapWithKeys(function (string $key) use ($team) {
                return [$key => [
                    'limit' => $team?->quota($key),
                    'used' => $this->used($team, $key),
                ]];
            }),
        ]);
    }

    private function used(?Team $team, string $key): int
    {
        if (! $team) {
            return 0;
        }

        try {
            return match ($key) {
                'max_applications' => (int) $team->applications()->count(),
                'max_databases' => 0,
                'max_services' => 0,
                default => 0,
            };
        } catch (\Throwable) {
            return 0;
        }
    }
}
