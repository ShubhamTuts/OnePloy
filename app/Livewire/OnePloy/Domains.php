<?php

namespace App\Livewire\OnePloy;

use App\Models\OneployDomain;
use App\Services\OnePloy\ConnectResellerClient;
use Livewire\Component;

class Domains extends Component
{
    public string $query = '';

    public ?array $result = null;

    public function search(ConnectResellerClient $client): void
    {
        $domain = strtolower(trim($this->query));
        if ($domain === '') {
            return;
        }
        $this->result = [
            'availability' => $client->availability($domain),
            'suggestions' => $client->suggest($domain),
        ];
    }

    public function render()
    {
        return view('livewire.oneploy.domains', [
            'domains' => OneployDomain::query()->where('team_id', currentTeam()?->id)->latest()->get(),
        ]);
    }
}
