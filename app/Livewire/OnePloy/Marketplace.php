<?php

namespace App\Livewire\OnePloy;

use App\Models\OneployMarketplaceApp;
use Livewire\Component;

class Marketplace extends Component
{
    public function render()
    {
        return view('livewire.oneploy.marketplace', [
            'apps' => OneployMarketplaceApp::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
