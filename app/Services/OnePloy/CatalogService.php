<?php

namespace App\Services\OnePloy;

use App\Models\OneployMarketplaceApp;
use App\Models\OneployPlan;
use App\Models\OneployPlanVersion;
use App\Models\OneployPrice;
use App\Models\OneployProduct;
use Illuminate\Support\Facades\DB;

class CatalogService
{
    public function seed(): void
    {
        DB::transaction(function () {
            $families = [
                ['slug' => 'app-hosting', 'name' => 'App Hosting', 'family' => 'app_hosting', 'description' => 'Git, Docker, and Compose deployments on OnePloy-managed or BYO servers.'],
                ['slug' => 'wordpress-cloud', 'name' => 'WordPress Cloud', 'family' => 'wordpress', 'description' => 'Managed WordPress with SSL, backups, and persistent storage.'],
                ['slug' => 'game-cloud', 'name' => 'Game Cloud', 'family' => 'game', 'description' => 'Managed game servers with TCP/UDP exposure.'],
                ['slug' => 'ai-apps', 'name' => 'AI Apps / Agent Cloud', 'family' => 'ai', 'description' => 'Managed n8n, OpenClaw, Hermes, and related agent runtimes.'],
                ['slug' => 'domains', 'name' => 'Domains', 'family' => 'domains', 'description' => 'Registrar-neutral domain registration and DNS.'],
                ['slug' => 'ai-gateway', 'name' => 'AI Gateway', 'family' => 'ai_gateway', 'description' => 'Provider-neutral AI proxy with usage accounting.'],
            ];

            foreach ($families as $index => $family) {
                $product = OneployProduct::updateOrCreate(['slug' => $family['slug']], $family + ['is_active' => true, 'sort_order' => $index]);
                $this->seedPlan($product, 'starter', 'Starter', 0, [
                    'projects.max' => 3,
                    'applications.max' => 5,
                    'databases.max' => 2,
                    'services.max' => 2,
                    'members.max' => 3,
                    'preview.enabled' => true,
                    'custom_domains.enabled' => true,
                    'api.enabled' => true,
                ], [
                    'USD' => ['monthly' => 1900, 'yearly' => 19000],
                    'INR' => ['monthly' => 149900, 'yearly' => 1499000],
                ]);
                $this->seedPlan($product, 'pro', 'Pro', 1, [
                    'projects.max' => 25,
                    'applications.max' => 50,
                    'databases.max' => 15,
                    'services.max' => 25,
                    'members.max' => 20,
                    'preview.enabled' => true,
                    'custom_domains.enabled' => true,
                    'api.enabled' => true,
                    'mcp.enabled' => true,
                    'ai_gateway.enabled' => $family['family'] !== 'domains',
                ], [
                    'USD' => ['monthly' => 4900, 'yearly' => 49000],
                    'INR' => ['monthly' => 399900, 'yearly' => 3999000],
                ]);
            }

            $this->seedMarketplace();
        });
    }

    public function catalogue(?string $currency = null, ?string $interval = null): array
    {
        $currency = strtoupper($currency ?: config('oneploy.storefront.default_currency', 'USD'));
        $interval ??= 'monthly';

        return OneployProduct::query()->where('is_active', true)->orderBy('sort_order')->with(['plans.versions.prices'])->get()
            ->map(function (OneployProduct $product) use ($currency, $interval) {
                return [
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'family' => $product->family,
                    'description' => $product->description,
                    'plans' => $product->plans->where('is_active', true)->map(function (OneployPlan $plan) use ($currency, $interval) {
                        $version = $plan->publishedVersion();
                        $price = $version?->prices->first(fn (OneployPrice $p) => $p->currency === $currency && $p->interval === $interval && $p->status === 'active');

                        return [
                            'slug' => $plan->slug,
                            'name' => $plan->name,
                            'features' => $version?->features ?? [],
                            'entitlements' => $version?->entitlements ?? [],
                            'price' => $price ? [
                                'id' => $price->id,
                                'currency' => $price->currency,
                                'interval' => $price->interval,
                                'amount_minor' => $price->amount_minor,
                                'formatted' => $price->formatted(),
                            ] : null,
                        ];
                    })->values(),
                ];
            })->all();
    }

    private function seedPlan(OneployProduct $product, string $slug, string $name, int $sort, array $entitlements, array $prices): void
    {
        $plan = OneployPlan::updateOrCreate(
            ['product_id' => $product->id, 'slug' => $slug],
            ['name' => $name, 'is_active' => true, 'sort_order' => $sort]
        );

        $version = OneployPlanVersion::firstOrCreate(
            ['plan_id' => $plan->id, 'version' => 1],
            [
                'status' => 'published',
                'published_at' => now(),
                'effective_from' => now(),
                'features' => array_keys(array_filter($entitlements, fn ($value) => $value === true)),
                'entitlements' => $entitlements,
            ]
        );

        foreach ($prices as $currency => $intervals) {
            foreach ($intervals as $interval => $amount) {
                OneployPrice::updateOrCreate(
                    [
                        'plan_version_id' => $version->id,
                        'currency' => $currency,
                        'interval' => $interval,
                    ],
                    [
                        'amount_minor' => $amount,
                        'status' => 'active',
                    ]
                );
            }
        }
    }

    private function seedMarketplace(): void
    {
        $apps = [
            ['slug' => 'wordpress', 'name' => 'WordPress', 'category' => 'cms', 'certification' => 'managed', 'product_level' => 'managed_product', 'template_file' => 'wordpress-with-mariadb.yaml'],
            ['slug' => 'n8n', 'name' => 'n8n', 'category' => 'automation', 'certification' => 'managed', 'product_level' => 'managed_app', 'template_file' => 'n8n.yaml'],
            ['slug' => 'openclaw', 'name' => 'OpenClaw', 'category' => 'ai', 'certification' => 'verified', 'product_level' => 'managed_app', 'template_file' => 'openclaw.yaml'],
            ['slug' => 'hermes-agent', 'name' => 'Hermes Agent', 'category' => 'ai', 'certification' => 'verified', 'product_level' => 'managed_app', 'template_file' => 'hermes-agent-with-webui.yaml'],
            ['slug' => 'minecraft', 'name' => 'Minecraft', 'category' => 'game', 'certification' => 'managed', 'product_level' => 'managed_product', 'template_file' => 'minecraft.yaml'],
        ];

        foreach ($apps as $app) {
            OneployMarketplaceApp::updateOrCreate(['slug' => $app['slug']], $app + ['is_active' => true]);
        }
    }
}
