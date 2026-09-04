<?php

use App\Models\InstanceSettings;
use App\Models\OneployAiGatewayRequest;
use App\Models\OneployCommerceSubscription;
use App\Models\OneployPlan;
use App\Models\OneployPlanVersion;
use App\Models\OneployProduct;
use App\Models\OneployUsageLedger;
use App\Models\Team;
use App\Models\User;
use App\Services\OnePloy\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    config()->set('oneploy.ai_gateway', [
        'enabled' => true,
        'rate_limit_per_minute' => 30,
        'connect_timeout_seconds' => 2,
        'timeout_seconds' => 15,
        'providers' => [
            'openai' => [
                'base_url' => 'https://api.openai.test/v1',
                'api_key' => 'server-owned-secret',
            ],
        ],
        'models' => [
            'oneploy-fast' => [
                'provider' => 'openai',
                'upstream_model' => 'gpt-4o-mini',
            ],
        ],
    ]);

    Http::preventStrayRequests();

    $this->team = Team::factory()->create();
    $this->aiSubscription = createAiGatewaySubscription($this->team);
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('ai-gateway', ['write'])->plainTextToken;
});

function createAiGatewaySubscription(Team $team, int $monthlyTokens = 1_000_000): OneployCommerceSubscription
{
    $product = OneployProduct::create([
        'slug' => 'ai-gateway-'.fake()->unique()->slug(),
        'name' => 'AI Gateway',
        'family' => 'ai_gateway',
    ]);
    $plan = OneployPlan::create([
        'product_id' => $product->id,
        'slug' => 'gateway-'.fake()->unique()->word(),
        'name' => 'Gateway Plan',
    ]);
    $version = OneployPlanVersion::create([
        'plan_id' => $plan->id,
        'version' => 1,
        'status' => 'published',
        'entitlements' => [
            'ai_gateway.enabled' => true,
            'ai.tokens.monthly' => $monthlyTokens,
        ],
    ]);

    return OneployCommerceSubscription::create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'plan_version_id' => $version->id,
        'status' => 'active',
        'current_period_ends_at' => now()->addMonth(),
        'entitlement_snapshot' => $version->entitlements,
    ]);
}

function oneployAiGatewayPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'model' => 'oneploy-fast',
        'messages' => [
            ['role' => 'user', 'content' => 'Give me a deployment checklist.'],
        ],
        'temperature' => 0.2,
        'max_tokens' => 200,
    ], $overrides);
}

function oneployAiGatewayHeaders(string $token, ?string $idempotencyKey = null): array
{
    return array_filter([
        'Authorization' => 'Bearer '.$token,
        'Idempotency-Key' => $idempotencyKey,
    ]);
}

test('an authorized team token proxies an allowlisted model with only the server credential', function () {
    Http::fake([
        'https://api.openai.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'gpt-4o-mini',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Ship it safely.']]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5, 'total_tokens' => 17],
        ]),
    ]);

    $this->withHeaders(oneployAiGatewayHeaders($this->token, 'deploy-checklist-001'))
        ->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertOk()
        ->assertJsonPath('id', 'chatcmpl-1')
        ->assertJsonPath('choices.0.message.content', 'Ship it safely.');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.openai.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer server-owned-secret')
            && $request->hasHeader('Idempotency-Key', 'deploy-checklist-001')
            && $request['model'] === 'gpt-4o-mini'
            && ! array_key_exists('api_key', $request->data());
    });
});

test('authentication team membership and write ability are enforced before proxying', function () {
    Http::fake();

    $this->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertUnauthorized();

    $readToken = $this->user->createToken('read-ai', ['read'])->plainTextToken;
    $this->withHeaders(oneployAiGatewayHeaders($readToken))
        ->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertForbidden();

    $otherTeam = Team::factory()->create();
    $this->user->tokens()->where('name', 'ai-gateway')->update(['team_id' => $otherTeam->id]);

    $this->withHeaders(oneployAiGatewayHeaders($this->token))
        ->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the dedicated gateway limit is isolated by token team', function () {
    config()->set('oneploy.ai_gateway.rate_limit_per_minute', 1);
    Http::fake([
        'https://api.openai.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-rate-limited',
            'choices' => [],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]),
    ]);

    $this->withHeaders(oneployAiGatewayHeaders($this->token))
        ->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertOk();
    $this->withHeaders(oneployAiGatewayHeaders($this->token))
        ->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertTooManyRequests();

    $otherTeam = Team::factory()->create();
    createAiGatewaySubscription($otherTeam);
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);
    $otherToken = $otherUser->createToken('other-team-ai', ['write'])->plainTextToken;
    Auth::forgetGuards();

    $this->withHeaders(oneployAiGatewayHeaders($otherToken))
        ->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertOk();

    Http::assertSentCount(2);
    expect(OneployUsageLedger::query()->where('meter', OneployUsageLedger::METER_AI_GATEWAY_REQUESTS)->count())
        ->toBe(2);
});

test('unknown models streaming and client supplied provider credentials are rejected', function (array $payload) {
    Http::fake();

    $this->withHeaders(oneployAiGatewayHeaders($this->token))
        ->postJson('/api/v1/ai/chat/completions', $payload)
        ->assertUnprocessable();

    Http::assertNothingSent();
})->with([
    'unknown model' => fn () => oneployAiGatewayPayload(['model' => 'unapproved-model']),
    'streaming' => fn () => oneployAiGatewayPayload(['stream' => true]),
    'client credential' => fn () => oneployAiGatewayPayload(['api_key' => 'client-secret']),
]);

test('provider failures are sanitized and do not increment successful usage', function () {
    Http::fake([
        'https://api.openai.test/v1/chat/completions' => Http::response([
            'error' => [
                'message' => 'Incorrect API key: server-owned-secret',
                'type' => 'invalid_request_error',
            ],
        ], 401),
    ]);

    $this->withHeaders(oneployAiGatewayHeaders($this->token, 'provider-failure-001'))
        ->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'upstream_error')
        ->assertJsonMissing(['server-owned-secret']);

    expect(OneployUsageLedger::query()->count())->toBe(0)
        ->and(OneployAiGatewayRequest::query()->firstOrFail()->status)
        ->toBe(OneployAiGatewayRequest::STATUS_FAILED);
});

test('provider credentials are never sent to a plaintext upstream endpoint', function () {
    config()->set('oneploy.ai_gateway.providers.openai.base_url', 'http://api.openai.test/v1');
    Http::fake();

    $this->withHeaders(oneployAiGatewayHeaders($this->token))
        ->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'provider_not_configured');

    Http::assertNothingSent();
});

test('successful usage is recorded once and an idempotent replay avoids a second provider charge', function () {
    Http::fake([
        'https://api.openai.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-metered',
            'object' => 'chat.completion',
            'model' => 'gpt-4o-mini',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Done.']]],
            'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 2, 'total_tokens' => 10],
        ]),
    ]);

    $headers = oneployAiGatewayHeaders($this->token, 'metered-completion-001');
    $payload = oneployAiGatewayPayload();

    $this->withHeaders($headers)->postJson('/api/v1/ai/chat/completions', $payload)->assertOk();
    $this->withHeaders($headers)->postJson('/api/v1/ai/chat/completions', $payload)
        ->assertOk()
        ->assertHeader('X-OnePloy-Idempotent-Replay', 'true');

    Http::assertSentCount(1);

    $ledger = OneployUsageLedger::query()
        ->whereBelongsTo($this->team)
        ->where('meter', OneployUsageLedger::METER_AI_GATEWAY_REQUESTS)
        ->firstOrFail();

    expect($ledger->quantity)->toBe(1)
        ->and($ledger->dimensions)->toMatchArray([
            'prompt_tokens' => 8,
            'completion_tokens' => 2,
            'total_tokens' => 10,
        ])
        ->and(OneployAiGatewayRequest::query()->whereBelongsTo($this->team)->count())->toBe(1)
        ->and(OneployAiGatewayRequest::query()->firstOrFail()->response_payload)->toHaveKey('id', 'chatcmpl-metered')
        ->and((string) DB::table('oneploy_ai_gateway_requests')->value('response_payload'))
        ->not->toContain('chatcmpl-metered');
});

test('reusing an idempotency key with a different request is rejected', function () {
    Http::fake([
        'https://api.openai.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-once',
            'choices' => [],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]),
    ]);

    $headers = oneployAiGatewayHeaders($this->token, 'same-key-different-request');

    $this->withHeaders($headers)->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())->assertOk();
    $this->withHeaders($headers)->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload([
        'messages' => [['role' => 'user', 'content' => 'A different prompt.']],
    ]))->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_conflict');

    Http::assertSentCount(1);
});

test('a team without an active AI Gateway entitlement cannot spend provider credentials', function () {
    $this->aiSubscription->delete();
    Http::fake();

    $this->withHeaders(oneployAiGatewayHeaders($this->token))
        ->postJson('/api/v1/ai/chat/completions', oneployAiGatewayPayload())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'gateway_not_entitled');

    Http::assertNothingSent();
});

test('monthly token budget reserves request capacity before calling the provider', function () {
    $this->aiSubscription->update([
        'entitlement_snapshot' => [
            'ai_gateway.enabled' => true,
            'ai.tokens.monthly' => 11,
        ],
    ]);
    Http::fake([
        'https://api.openai.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-budget',
            'choices' => [],
            'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 1, 'total_tokens' => 10],
        ]),
    ]);
    $payload = oneployAiGatewayPayload([
        'messages' => [['role' => 'user', 'content' => '123456789']],
        'max_tokens' => 1,
    ]);

    $this->withHeaders(oneployAiGatewayHeaders($this->token, 'monthly-budget-first'))
        ->postJson('/api/v1/ai/chat/completions', $payload)
        ->assertOk();
    $this->withHeaders(oneployAiGatewayHeaders($this->token, 'monthly-budget-second'))
        ->postJson('/api/v1/ai/chat/completions', $payload)
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'monthly_token_budget_exhausted');

    Http::assertSentCount(1);
});

test('catalog seed exposes purchasable AI Gateway plans with enforceable token entitlements', function () {
    app(CatalogService::class)->seed();

    $product = OneployProduct::query()
        ->with('plans.versions')
        ->where('slug', 'ai-gateway')
        ->firstOrFail();

    expect($product->is_active)->toBeTrue()
        ->and($product->plans)->toHaveCount(2);

    foreach ($product->plans as $plan) {
        $entitlements = $plan->publishedVersion()?->entitlements;
        expect($entitlements)->toHaveKey('ai_gateway.enabled', true)
            ->and($entitlements['ai.tokens.monthly'] ?? null)->toBeInt()->toBeGreaterThan(0)
            ->and($entitlements)->not->toHaveKey('applications.max');
    }
});
