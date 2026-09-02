<div>
    <x-slot:title>
        Update Settings | OnePloy
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            {{-- Exclude is_auto_update_enabled (instantSave) so the bar does not flash. --}}
            <x-unsaved-bar action="submit" targets="update_check_frequency,auto_update_frequency" />

            <x-application.settings-section title="Update OnePloy"
                helper="Install the latest OnePloy version manually when an update is available.">
                <livewire:upgrade :full-button="true" key="settings-upgrade" />
            </x-application.settings-section>

            <x-application.settings-section title="Update checks">
                @if (!config('oneploy.updates_enabled'))
                    <x-callout type="warning" title="Update checks unavailable">
                        OnePloy does not contact the inherited upstream release service.
                    </x-callout>
                @else
                    <x-slot:actions>
                        <x-forms.button type="button" wire:click="checkManually">
                            <x-reicon name="refresh" class="size-3.5" />
                            Check now
                        </x-forms.button>
                    </x-slot:actions>
                    <x-forms.input required id="update_check_frequency" label="Check frequency"
                        placeholder="0 * * * *"
                        helper="A cron expression or preset such as hourly, daily, weekly, monthly, or yearly." />
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="Automatic updates">
                @if (!config('oneploy.updates_enabled'))
                    <x-callout type="warning" title="Automatic updates unavailable">
                        This fork intentionally blocks the inherited upstream update channel. Keep automatic updates disabled until OnePloy publishes verified images, metadata, and rollback artifacts.
                    </x-callout>
                @else
                    <div class="grid gap-4 lg:grid-cols-2">
                    @if (!is_null(config('constants.coolify.autoupdate', null)))
                        <x-forms.listbox disabled id="is_auto_update_enabled" label="Automatic updates"
                            helper="Controlled by the AUTOUPDATE environment variable." :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                    @else
                        <x-forms.listbox id="is_auto_update_enabled" label="Automatic updates"
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                    @endif

                    @if (is_null(config('constants.coolify.autoupdate', null)) && $is_auto_update_enabled)
                        <x-forms.input required id="auto_update_frequency" label="Update frequency"
                            placeholder="0 0 * * *"
                            helper="Cron expression or preset for installing updates." />
                    @else
                        <x-forms.input label="Update frequency" disabled placeholder="Disabled" />
                    @endif
                    </div>
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="Image registry">
                <div class="max-w-md">
                    <x-forms.listbox id="docker_registry_url" label="Docker registry" :options="[
                        ['value' => 'docker.io', 'label' => 'Docker Hub'],
                        ['value' => 'ghcr.io', 'label' => 'GitHub Container Registry'],
                    ]"
                        helper="Switch registries if the current source is rate limited." />
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
