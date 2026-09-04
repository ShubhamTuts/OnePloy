<?php

namespace App\Services\OnePloy;

use App\Models\OneployMarketplaceApp;
use App\Models\OneployPlan;
use App\Models\OneployPlanVersion;
use App\Models\OneployPrice;
use App\Models\OneployProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CatalogService
{
    private const MARKETPLACE_SOURCE = 'service_templates';

    private const CURATED_MARKETPLACE_APPS = [
        'wordpress-with-mariadb' => [
            'slug' => 'wordpress',
            'name' => 'WordPress',
            'category' => 'cms',
            'certification' => 'beta',
            'product_level' => 'deployable_template',
            'template_file' => 'wordpress-with-mariadb.yaml',
        ],
        'n8n' => [
            'slug' => 'n8n',
            'name' => 'n8n',
            'category' => 'automation',
            'certification' => 'beta',
            'product_level' => 'deployable_template',
            'template_file' => 'n8n.yaml',
        ],
        'openclaw' => [
            'slug' => 'openclaw',
            'name' => 'OpenClaw',
            'category' => 'ai',
            'certification' => 'beta',
            'product_level' => 'deployable_template',
            'template_file' => 'openclaw.yaml',
        ],
        'hermes-agent-with-webui' => [
            'slug' => 'hermes-agent',
            'name' => 'Hermes Agent',
            'category' => 'ai',
            'certification' => 'beta',
            'product_level' => 'deployable_template',
            'template_file' => 'hermes-agent-with-webui.yaml',
        ],
        'minecraft' => [
            'slug' => 'minecraft',
            'name' => 'Minecraft',
            'category' => 'game',
            'certification' => 'beta',
            'product_level' => 'deployable_template',
            'template_file' => 'minecraft.yaml',
        ],
    ];

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
                $isAiGateway = $family['family'] === 'ai_gateway';
                $product = OneployProduct::updateOrCreate(
                    ['slug' => $family['slug']],
                    $family + [
                        'is_active' => in_array($family['family'], ['app_hosting', 'ai_gateway'], true),
                        'sort_order' => $index,
                    ]
                );
                $starterEntitlements = $isAiGateway ? [
                    'ai_gateway.enabled' => true,
                    'ai.tokens.monthly' => 1_000_000,
                ] : [
                    'projects.max' => 3,
                    'applications.max' => 5,
                    'databases.max' => 2,
                    'services.max' => 2,
                    'members.max' => 3,
                    'preview.enabled' => true,
                    'custom_domains.enabled' => true,
                    'api.enabled' => true,
                ];
                $proEntitlements = $isAiGateway ? [
                    'ai_gateway.enabled' => true,
                    'ai.tokens.monthly' => 5_000_000,
                ] : [
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
                ];

                $this->seedPlan($product, 'starter', 'Starter', 0, $starterEntitlements, [
                    'USD' => ['monthly' => 1900, 'yearly' => 19000],
                    'INR' => ['monthly' => 149900, 'yearly' => 1499000],
                ]);
                $this->seedPlan($product, 'pro', 'Pro', 1, $proEntitlements, [
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
        $effectiveAt = now();

        return OneployProduct::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with([
                'plans' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'plans.versions' => fn ($query) => $query
                    ->where('status', 'published')
                    ->effectiveAt($effectiveAt)
                    ->orderByDesc('version')
                    ->orderByDesc('id'),
                'plans.versions.prices' => fn ($query) => $query
                    ->where('currency', $currency)
                    ->where('interval', $interval)
                    ->where('status', 'active')
                    ->effectiveAt($effectiveAt)
                    ->orderByDesc('effective_from')
                    ->orderByDesc('id'),
            ])
            ->get()
            ->map(function (OneployProduct $product) {
                return [
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'family' => $product->family,
                    'description' => $product->description,
                    'plans' => $product->plans->map(function (OneployPlan $plan) {
                        $version = $plan->versions->first();
                        $price = $version?->prices->first();

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
                $identity = [
                    'plan_version_id' => $version->id,
                    'currency' => $currency,
                    'interval' => $interval,
                ];
                $price = OneployPrice::query()->where($identity)->orderBy('id')->first();

                if ($price && $price->amount_minor !== $amount) {
                    throw new RuntimeException(
                        "Seeded price amount conflicts with the persisted price for {$product->slug}/{$slug} {$currency} {$interval}."
                    );
                }

                if (! $price) {
                    OneployPrice::create($identity + [
                        'amount_minor' => $amount,
                        'status' => 'active',
                    ]);
                } elseif ($price->status !== 'active') {
                    $price->update(['status' => 'active']);
                }
            }
        }
    }

    private function seedMarketplace(): void
    {
        $activeCommunitySlugs = [];

        foreach (get_service_templates() as $templateKey => $template) {
            $app = $this->marketplaceAppFromTemplate($templateKey, $template);
            if ($app === null) {
                continue;
            }

            OneployMarketplaceApp::updateOrCreate(
                ['slug' => $app['slug']],
                $app + ['is_active' => true],
            );

            if ($app['certification'] === 'community') {
                $activeCommunitySlugs[] = $app['slug'];
            }
        }

        foreach (self::CURATED_MARKETPLACE_APPS as $app) {
            OneployMarketplaceApp::updateOrCreate(
                ['slug' => $app['slug']],
                $app + ['is_active' => true],
            );
        }

        OneployMarketplaceApp::query()
            ->where('certification', 'community')
            ->where('metadata->source', self::MARKETPLACE_SOURCE)
            ->when(
                $activeCommunitySlugs !== [],
                fn ($query) => $query->whereNotIn('slug', array_unique($activeCommunitySlugs)),
            )
            ->update(['is_active' => false]);
    }

    private function marketplaceAppFromTemplate(mixed $templateKey, mixed $template): ?array
    {
        if (! is_string($templateKey) || (! is_array($template) && ! is_object($template))) {
            return null;
        }

        $templateKey = trim($templateKey);
        $slug = Str::slug($templateKey);
        if ($slug === '') {
            return null;
        }

        $curated = self::CURATED_MARKETPLACE_APPS[$templateKey] ?? [];

        return $curated + [
            'slug' => $slug,
            'name' => Str::headline($templateKey),
            'category' => $this->marketplaceTemplateString($template, 'category') ?? 'other',
            'certification' => 'community',
            'product_level' => 'template',
            'template_file' => $templateKey.'.yaml',
            'description' => $this->marketplaceTemplateString($template, 'slogan'),
            'metadata' => array_filter([
                'source' => self::MARKETPLACE_SOURCE,
                'template_key' => $templateKey,
                'documentation' => $this->marketplaceTemplateString($template, 'documentation'),
                'logo' => $this->marketplaceTemplateString($template, 'logo'),
                'tags' => $this->marketplaceTemplateTags($template),
                'minversion' => $this->marketplaceTemplateString($template, 'minversion'),
                'template_last_updated_at' => $this->marketplaceTemplateString($template, 'template_last_updated_at'),
                'port' => data_get($template, 'port'),
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    private function marketplaceTemplateString(array|object $template, string $key): ?string
    {
        $value = data_get($template, $key);

        return is_string($value) && filled(trim($value)) ? trim($value) : null;
    }

    private function marketplaceTemplateTags(array|object $template): ?array
    {
        $tags = data_get($template, 'tags');
        if (! is_array($tags)) {
            return null;
        }

        return collect($tags)
            ->filter(fn (mixed $tag): bool => is_string($tag) && filled(trim($tag)))
            ->map(fn (string $tag): string => trim($tag))
            ->values()
            ->all();
    }
}
