<?php

namespace App\Livewire\OnePloy;

use App\Models\OneployCheckoutSession;
use App\Models\OneployCommerceSubscription;
use App\Models\OneployOrder;
use App\Services\OnePloy\CatalogService;
use Livewire\Component;

class Billing extends Component
{
    public function render(CatalogService $catalog)
    {
        $team = currentTeam();

        return view('livewire.oneploy.billing', [
            'subscription' => $team ? OneployCommerceSubscription::query()->where('team_id', $team->id)->latest()->first() : null,
            'orders' => $team ? OneployOrder::query()->where('team_id', $team->id)->latest()->limit(20)->get() : collect(),
            'checkouts' => $team ? OneployCheckoutSession::query()->where('team_id', $team->id)->latest()->limit(10)->get() : collect(),
            'catalogue' => $catalog->catalogue(),
        ]);
    }
}
