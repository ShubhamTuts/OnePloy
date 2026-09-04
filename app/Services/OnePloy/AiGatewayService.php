<?php

namespace App\Services\OnePloy;

use App\Exceptions\OneployAiGatewayException;
use App\Models\OneployAiGatewayRequest;
use App\Models\OneployUsageLedger;
use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AiGatewayService
{
    public function __construct(private readonly AiGatewayEntitlement $entitlements) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, status: int, replay: bool}
     */
    public function complete(
        Team $team,
        int $userId,
        array $payload,
        ?string $idempotencyKey = null,
    ): array {
        if (! config('oneploy.ai_gateway.enabled', false)) {
            throw new OneployAiGatewayException(
                'gateway_disabled',
                503,
                'The AI Gateway is not enabled.',
            );
        }

        $payload['max_tokens'] ??= max(
            1,
            min(32768, (int) config('oneploy.ai_gateway.default_max_tokens', 1024)),
        );

        $model = (string) $payload['model'];
        $definition = $this->modelDefinition($model);
        $monthlyTokenLimit = $this->entitlements->monthlyTokenLimit($team);
        $provider = $this->providerDefinition($definition['provider']);
        $requestHash = $this->requestHash($payload);
        $reservedTokens = $this->reservedTokens($payload);

        [$requestRecord, $replay] = $this->beginRequest(
            $team,
            $userId,
            $idempotencyKey,
            $requestHash,
            $definition,
            $model,
            $reservedTokens,
            $monthlyTokenLimit,
        );

        if ($replay) {
            return [
                'payload' => $requestRecord->response_payload ?? [],
                'status' => $requestRecord->upstream_status ?? 200,
                'replay' => true,
            ];
        }

        $upstreamPayload = $payload;
        $upstreamPayload['model'] = $definition['upstream_model'];

        try {
            $response = $this->client($provider, $idempotencyKey)
                ->post($provider['base_url'].'/chat/completions', $upstreamPayload);
        } catch (Throwable $exception) {
            $this->recordFailure($requestRecord, null, 'upstream_unavailable');

            throw new OneployAiGatewayException(
                'upstream_unavailable',
                502,
                'The AI provider is temporarily unavailable.',
            );
        }

        if (! $response->successful()) {
            $code = $response->tooManyRequests() ? 'upstream_rate_limited' : 'upstream_error';
            $status = $response->tooManyRequests() ? 503 : 502;
            $this->recordFailure($requestRecord, $response->status(), $code);

            throw new OneployAiGatewayException(
                $code,
                $status,
                'The AI provider could not complete the request.',
            );
        }

        $responsePayload = $response->json();
        if (! is_array($responsePayload) || ! array_key_exists('choices', $responsePayload)) {
            $this->recordFailure($requestRecord, $response->status(), 'invalid_upstream_response');

            throw new OneployAiGatewayException(
                'invalid_upstream_response',
                502,
                'The AI provider returned an invalid response.',
            );
        }

        $usage = $this->usageFrom($responsePayload);
        $this->recordSuccess($team, $requestRecord, $response->status(), $responsePayload, $usage);

        return [
            'payload' => $responsePayload,
            'status' => $response->status(),
            'replay' => false,
        ];
    }

    /**
     * @return array{provider: string, upstream_model: string}
     */
    private function modelDefinition(string $model): array
    {
        $models = config('oneploy.ai_gateway.models', []);
        $definition = is_array($models) ? ($models[$model] ?? null) : null;

        if (! is_array($definition)
            || ! is_string($definition['provider'] ?? null)
            || ! is_string($definition['upstream_model'] ?? null)
            || blank($definition['provider'])
            || blank($definition['upstream_model'])) {
            throw new OneployAiGatewayException(
                'model_not_allowed',
                422,
                'The selected model is not available.',
            );
        }

        return [
            'provider' => $definition['provider'],
            'upstream_model' => $definition['upstream_model'],
        ];
    }

    /**
     * @return array{base_url: string, api_key: string}
     */
    private function providerDefinition(string $provider): array
    {
        $providers = config('oneploy.ai_gateway.providers', []);
        $definition = is_array($providers) ? ($providers[$provider] ?? null) : null;
        $baseUrl = is_array($definition) ? ($definition['base_url'] ?? null) : null;
        $apiKey = is_array($definition) ? ($definition['api_key'] ?? null) : null;
        $scheme = is_string($baseUrl) ? parse_url($baseUrl, PHP_URL_SCHEME) : null;

        if (! is_string($baseUrl)
            || $scheme !== 'https'
            || ! is_string($apiKey)
            || blank($apiKey)) {
            throw new OneployAiGatewayException(
                'provider_not_configured',
                503,
                'The AI provider is not configured.',
            );
        }

        return [
            'base_url' => rtrim($baseUrl, '/'),
            'api_key' => $apiKey,
        ];
    }

    /**
     * @param  array{provider: string, upstream_model: string}  $definition
     * @return array{0: OneployAiGatewayRequest, 1: bool}
     */
    private function beginRequest(
        Team $team,
        int $userId,
        ?string $idempotencyKey,
        string $requestHash,
        array $definition,
        string $model,
        int $reservedTokens,
        ?int $monthlyTokenLimit,
    ): array {
        return DB::transaction(function () use (
            $team,
            $userId,
            $idempotencyKey,
            $requestHash,
            $definition,
            $model,
            $reservedTokens,
            $monthlyTokenLimit,
        ): array {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $keyHash = $idempotencyKey === null ? null : hash('sha256', $idempotencyKey);
            $existing = $keyHash === null
                ? null
                : OneployAiGatewayRequest::query()
                    ->whereBelongsTo($team)
                    ->where('idempotency_key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new OneployAiGatewayException(
                        'idempotency_conflict',
                        409,
                        'The idempotency key was already used for a different request.',
                    );
                }

                if ($existing->status === OneployAiGatewayRequest::STATUS_SUCCEEDED
                    && is_array($existing->response_payload)) {
                    return [$existing, true];
                }

                if ($existing->status === OneployAiGatewayRequest::STATUS_FAILED) {
                    throw new OneployAiGatewayException(
                        $existing->error_code ?: 'upstream_error',
                        502,
                        'The previous request with this idempotency key failed.',
                    );
                }

                throw new OneployAiGatewayException(
                    'request_in_progress',
                    409,
                    'A request with this idempotency key is already in progress.',
                );
            }

            $billingPeriod = now()->utc()->format('Y-m');
            if ($monthlyTokenLimit !== null) {
                $ledger = OneployUsageLedger::query()
                    ->whereBelongsTo($team)
                    ->where('meter', OneployUsageLedger::METER_AI_GATEWAY_REQUESTS)
                    ->where('period', $billingPeriod)
                    ->lockForUpdate()
                    ->first();
                $usedTokens = (int) data_get($ledger?->dimensions, 'total_tokens', 0);
                $pendingTokens = (int) OneployAiGatewayRequest::query()
                    ->whereBelongsTo($team)
                    ->where('billing_period', $billingPeriod)
                    ->where('status', OneployAiGatewayRequest::STATUS_PENDING)
                    ->sum('reserved_tokens');

                if (($usedTokens + $pendingTokens + $reservedTokens) > $monthlyTokenLimit) {
                    throw new OneployAiGatewayException(
                        'monthly_token_budget_exhausted',
                        429,
                        'The monthly AI token budget is exhausted.',
                    );
                }
            }

            return [
                OneployAiGatewayRequest::query()->create([
                    'team_id' => $team->id,
                    'user_id' => $userId,
                    'idempotency_key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'provider' => $definition['provider'],
                    'model' => $model,
                    'upstream_model' => $definition['upstream_model'],
                    'billing_period' => $billingPeriod,
                    'reserved_tokens' => $reservedTokens,
                ]),
                false,
            ];
        });
    }

    /**
     * @param  array{base_url: string, api_key: string}  $provider
     */
    private function client(array $provider, ?string $idempotencyKey): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withToken($provider['api_key'])
            ->connectTimeout(max(1, (int) config('oneploy.ai_gateway.connect_timeout_seconds', 3)))
            ->timeout(max(1, (int) config('oneploy.ai_gateway.timeout_seconds', 30)));

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        $attempts = $idempotencyKey === null
            ? 1
            : max(1, min(3, (int) config('oneploy.ai_gateway.connection_attempts', 2)));

        return $request->retry(
            $attempts,
            100,
            fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->status() === 408),
            throw: false,
        );
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    private function usageFrom(array $responsePayload): array
    {
        $promptTokens = max(0, (int) data_get($responsePayload, 'usage.prompt_tokens', 0));
        $completionTokens = max(0, (int) data_get($responsePayload, 'usage.completion_tokens', 0));
        $totalTokens = max(
            0,
            (int) data_get($responsePayload, 'usage.total_tokens', $promptTokens + $completionTokens),
        );

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ];
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     * @param  array{prompt_tokens: int, completion_tokens: int, total_tokens: int}  $usage
     */
    private function recordSuccess(
        Team $team,
        OneployAiGatewayRequest $requestRecord,
        int $upstreamStatus,
        array $responsePayload,
        array $usage,
    ): void {
        DB::transaction(function () use ($team, $requestRecord, $upstreamStatus, $responsePayload, $usage): void {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $lockedRequest = OneployAiGatewayRequest::query()
                ->whereKey($requestRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest->update([
                'status' => OneployAiGatewayRequest::STATUS_SUCCEEDED,
                'upstream_status' => $upstreamStatus,
                'response_payload' => $responsePayload,
                ...$usage,
                'completed_at' => now(),
            ]);

            $period = now()->utc()->format('Y-m');
            $ledger = OneployUsageLedger::query()->firstOrCreate(
                [
                    'team_id' => $team->id,
                    'meter' => OneployUsageLedger::METER_AI_GATEWAY_REQUESTS,
                    'period' => $period,
                ],
                [
                    'quantity' => 0,
                    'dimensions' => [
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0,
                        'total_tokens' => 0,
                    ],
                ],
            );
            $dimensions = $ledger->dimensions ?? [];

            $ledger->update([
                'quantity' => $ledger->quantity + 1,
                'dimensions' => [
                    'prompt_tokens' => (int) ($dimensions['prompt_tokens'] ?? 0) + $usage['prompt_tokens'],
                    'completion_tokens' => (int) ($dimensions['completion_tokens'] ?? 0) + $usage['completion_tokens'],
                    'total_tokens' => (int) ($dimensions['total_tokens'] ?? 0) + $usage['total_tokens'],
                ],
            ]);
        });
    }

    private function recordFailure(
        OneployAiGatewayRequest $requestRecord,
        ?int $upstreamStatus,
        string $errorCode,
    ): void {
        $requestRecord->update([
            'status' => OneployAiGatewayRequest::STATUS_FAILED,
            'upstream_status' => $upstreamStatus,
            'error_code' => $errorCode,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestHash(array $payload): string
    {
        return hash(
            'sha256',
            json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reservedTokens(array $payload): int
    {
        $promptBytes = collect($payload['messages'])
            ->sum(fn (array $message): int => strlen($message['content']));

        return $promptBytes + (int) $payload['max_tokens'];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
