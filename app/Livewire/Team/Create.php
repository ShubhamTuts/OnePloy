<?php

namespace App\Livewire\Team;

use App\Actions\Team\CreateTenant;
use App\Support\ValidationPatterns;
use Livewire\Component;

class Create extends Component
{
    public string $name = '';

    public ?string $description = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

    public function submit()
    {
        try {
            $this->validate();
            $team = CreateTenant::run(
                owner: auth()->user(),
                name: $this->name,
                description: $this->description,
            );
            refreshSession($team);

            return redirectRoute($this, 'team.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
