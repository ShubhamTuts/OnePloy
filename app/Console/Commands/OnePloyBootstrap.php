<?php

namespace App\Console\Commands;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Jobs\OnePloy\CaptureManagedComputeCapacityJob;
use App\Models\Server;
use App\Services\OnePloy\CatalogService;
use App\Services\OnePloy\ManagedComputeNodeRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OnePloyBootstrap extends Command
{
    protected $signature = 'oneploy:bootstrap {--fqdn=} {--email=}';

    protected $description = 'Configure a fresh OnePloy instance: identity, local templates, FQDN/SSL proxy';

    public function handle(): int
    {
        $settings = instanceSettings();
        $fqdn = $this->option('fqdn') ?: env('APP_URL');
        $email = $this->option('email') ?: env('ONEPLOY_SUPPORT_EMAIL') ?: env('LETSENCRYPT_EMAIL');

        $payload = [
            'instance_name' => 'OnePloy',
            'is_sponsorship_popup_enabled' => false,
            'is_auto_update_enabled' => false,
            'do_not_track' => true,
            'is_api_enabled' => true,
            'smtp_from_name' => $settings->smtp_from_name ?: 'OnePloy',
        ];

        if (filled($fqdn) && str($fqdn)->startsWith(['http://', 'https://'])) {
            $payload['fqdn'] = rtrim((string) $fqdn, '/');
        } elseif (filled(env('APP_FQDN'))) {
            $payload['fqdn'] = 'https://'.ltrim((string) env('APP_FQDN'), '/');
        }

        if (filled($email) && blank($settings->smtp_from_address)) {
            $payload['smtp_from_address'] = $email;
        }

        $settings->update($payload);
        $this->loadLocalTemplates();
        app(CatalogService::class)->seed();
        $this->registerLocalManagedCompute();
        $this->startLocalhostProxy();

        $this->info('OnePloy bootstrap complete.');
        if (filled($settings->fresh()->fqdn)) {
            $this->info('Instance FQDN: '.$settings->fresh()->fqdn);
        }

        return self::SUCCESS;
    }

    private function loadLocalTemplates(): void
    {
        $candidates = [
            base_path('templates/service-templates.json'),
            base_path('templates/service-templates-latest.json'),
        ];

        foreach ($candidates as $path) {
            if (! File::exists($path)) {
                continue;
            }
            $json = File::get($path);
            if (filled($json)) {
                store_service_templates_bundle($json);
                $this->info('Loaded local service templates from '.$path);

                return;
            }
        }

        $this->warn('No local service-templates JSON found.');
    }

    private function startLocalhostProxy(): void
    {
        $server = Server::find(0);
        if (! $server) {
            return;
        }

        try {
            $server->setupDynamicProxyConfiguration();
            $shouldStart = CheckProxy::run($server);
            if ($shouldStart) {
                StartProxy::run($server, async: false);
                $this->info('Localhost proxy started.');
            }
        } catch (\Throwable $e) {
            $this->warn('Proxy start deferred: '.$e->getMessage());
        }
    }

    private function registerLocalManagedCompute(): void
    {
        if (! config('oneploy.platform')) {
            return;
        }

        $server = Server::find(0);
        if (! $server) {
            $this->warn('Localhost server is not available for managed compute registration.');

            return;
        }

        $node = app(ManagedComputeNodeRegistrar::class)->register(
            $server,
            (string) config('oneploy.scheduler.default_pool_slug', 'default'),
            (string) config('oneploy.scheduler.default_pool_name', 'Default Managed Pool'),
            (string) config('oneploy.scheduler.primary_region', 'local'),
            config('oneploy.scheduler.default_workload_classes', ['application', 'service', 'database']),
        );
        CaptureManagedComputeCapacityJob::dispatch($node->id);
        $this->info('Registered localhost in the managed compute pool; capacity probe queued.');
    }
}
