<?php

use App\Http\Controllers\Api\StorefrontController;
use App\Models\OneployMarketplaceApp;
use App\Services\OnePloy\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

test('catalog seed synchronizes service templates without persisting compose content', function () {
    storeMarketplaceTemplates([
        'custom-reporting' => marketplaceTemplate([
            'documentation' => 'https://docs.example.com/custom-reporting',
            'slogan' => 'Private reporting for growing teams.',
            'tags' => ['analytics', 'reporting'],
            'category' => 'analytics',
            'logo' => 'svgs/custom-reporting.svg',
            'minversion' => '4.0.0',
            'template_last_updated_at' => '2026-09-03T10:00:00+00:00',
            'port' => 8080,
            'compose' => 'services: {app: {environment: {SECRET_TOKEN: never-store-this}}}',
        ]),
        'wordpress-with-mariadb' => marketplaceTemplate([
            'category' => 'uncategorized',
            'compose' => 'services: {wordpress: {environment: {PASSWORD: another-secret}}}',
        ]),
        '' => marketplaceTemplate(),
        'invalid-entry' => null,
    ]);

    app(CatalogService::class)->seed();

    $communityApp = OneployMarketplaceApp::query()->where('slug', 'custom-reporting')->firstOrFail();
    expect($communityApp)
        ->name->toBe('Custom Reporting')
        ->category->toBe('analytics')
        ->description->toBe('Private reporting for growing teams.')
        ->certification->toBe('community')
        ->product_level->toBe('template')
        ->template_file->toBe('custom-reporting.yaml')
        ->and($communityApp->metadata)->toBe([
            'source' => 'service_templates',
            'template_key' => 'custom-reporting',
            'documentation' => 'https://docs.example.com/custom-reporting',
            'logo' => 'svgs/custom-reporting.svg',
            'tags' => ['analytics', 'reporting'],
            'minversion' => '4.0.0',
            'template_last_updated_at' => '2026-09-03T10:00:00+00:00',
            'port' => 8080,
        ])
        ->and(json_encode($communityApp->metadata, JSON_THROW_ON_ERROR))->not->toContain('compose', 'never-store-this');

    $wordpress = OneployMarketplaceApp::query()->where('slug', 'wordpress')->firstOrFail();
    expect($wordpress)
        ->name->toBe('WordPress')
        ->category->toBe('cms')
        ->certification->toBe('beta')
        ->product_level->toBe('deployable_template')
        ->template_file->toBe('wordpress-with-mariadb.yaml')
        ->is_active->toBeTrue()
        ->and($wordpress->metadata['template_key'])->toBe('wordpress-with-mariadb')
        ->and(json_encode($wordpress->metadata, JSON_THROW_ON_ERROR))->not->toContain('another-secret')
        ->and(OneployMarketplaceApp::query()->where('slug', 'invalid-entry')->exists())->toBeFalse();
});

test('catalog seed ingests every valid entry from the inherited local catalogue', function () {
    $expectedTemplateKeys = get_service_templates()
        ->filter(fn (mixed $template, mixed $key): bool => is_string($key) && trim($key) !== '' && (is_array($template) || is_object($template)))
        ->keys()
        ->map(fn (string $key): string => trim($key))
        ->sort()
        ->values()
        ->all();

    app(CatalogService::class)->seed();

    $syncedTemplateKeys = OneployMarketplaceApp::query()
        ->get()
        ->filter(fn (OneployMarketplaceApp $app): bool => data_get($app->metadata, 'source') === 'service_templates')
        ->pluck('metadata.template_key')
        ->sort()
        ->values()
        ->all();

    expect($syncedTemplateKeys)->toBe($expectedTemplateKeys);

    foreach ([
        'wordpress' => ['WordPress', 'cms'],
        'n8n' => ['n8n', 'automation'],
        'openclaw' => ['OpenClaw', 'ai'],
        'hermes-agent' => ['Hermes Agent', 'ai'],
        'minecraft' => ['Minecraft', 'game'],
    ] as $slug => [$name, $category]) {
        $curatedApp = OneployMarketplaceApp::query()->where('slug', $slug)->firstOrFail();
        expect($curatedApp)
            ->name->toBe($name)
            ->category->toBe($category)
            ->certification->toBe('beta')
            ->product_level->toBe('deployable_template')
            ->is_active->toBeTrue();
    }
});

test('catalog sync is idempotent and only deactivates stale synchronized community apps', function () {
    storeMarketplaceTemplates([
        'alpha-app' => marketplaceTemplate(),
        'wordpress-with-mariadb' => marketplaceTemplate(),
    ]);

    $catalog = app(CatalogService::class);
    $catalog->seed();
    $alpha = OneployMarketplaceApp::query()->where('slug', 'alpha-app')->firstOrFail();
    $initialIds = OneployMarketplaceApp::query()->orderBy('slug')->pluck('id', 'slug')->all();

    $catalog->seed();
    expect(OneployMarketplaceApp::query()->orderBy('slug')->pluck('id', 'slug')->all())->toBe($initialIds);

    OneployMarketplaceApp::query()->create([
        'slug' => 'manual-community-app',
        'name' => 'Manual Community App',
        'certification' => 'community',
        'metadata' => ['source' => 'manual'],
        'is_active' => true,
    ]);
    storeMarketplaceTemplates(['beta-app' => marketplaceTemplate()], '2026-09-03T11:00:00+00:00');

    $catalog->seed();

    expect($alpha->fresh()->is_active)->toBeFalse()
        ->and(OneployMarketplaceApp::query()->where('slug', 'beta-app')->firstOrFail()->is_active)->toBeTrue()
        ->and(OneployMarketplaceApp::query()->where('slug', 'manual-community-app')->firstOrFail()->is_active)->toBeTrue()
        ->and(OneployMarketplaceApp::query()->where('slug', 'wordpress')->firstOrFail()->is_active)->toBeTrue();

    $payload = app(StorefrontController::class)->applications()->getData(true);
    expect($payload['applications'])
        ->toHaveCount(OneployMarketplaceApp::query()->where('is_active', true)->count())
        ->and(collect($payload['applications'])->pluck('slug')->all())
        ->toContain('beta-app')
        ->not->toContain('alpha-app');
});

/** @param array<string, mixed> $overrides */
function marketplaceTemplate(array $overrides = []): array
{
    return array_replace([
        'documentation' => 'https://docs.example.com',
        'slogan' => 'A self-hosted service.',
        'tags' => ['self-hosted'],
        'category' => 'other',
        'logo' => 'svgs/default.svg',
        'minversion' => '0.0.0',
        'template_last_updated_at' => '2026-09-03T09:00:00+00:00',
        'compose' => 'services: {}',
    ], $overrides);
}

/** @param array<string, mixed> $templates */
function storeMarketplaceTemplates(array $templates, string $fetchedAt = '2026-09-03T10:00:00+00:00'): void
{
    Cache::forever(service_templates_cache_key(), [
        'fetched_at' => $fetchedAt,
        'json' => json_encode($templates, JSON_THROW_ON_ERROR),
    ]);
}
