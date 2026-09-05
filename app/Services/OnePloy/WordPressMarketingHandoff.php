<?php

namespace App\Services\OnePloy;

use App\Models\OneployPrice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class WordPressMarketingHandoff
{
    public const SESSION_KEY = 'oneploy.marketing_checkout';

    /** @return array{price_id: int, provider: string, issued_at: int, nonce: string, key_id: string, source: string, campaign: string, return_url: string|null} */
    public function validate(array $input): array
    {
        $secret = (string) config('oneploy.wordpress_bridge.secret');
        if ($secret === '') {
            throw new InvalidArgumentException('The WordPress bridge is not configured.');
        }

        $payload = $this->validatedPayload($input, requireSignature: true);

        $canonicalPayload = array_filter($payload, fn (mixed $value): bool => $value !== null);
        ksort($canonicalPayload);
        $expected = hash_hmac(
            'sha256',
            http_build_query($canonicalPayload, '', '&', PHP_QUERY_RFC3986),
            $secret,
        );
        if (! hash_equals($expected, strtolower((string) $input['signature']))) {
            throw new InvalidArgumentException('The WordPress checkout handoff signature is invalid.');
        }

        return $this->assertAcceptable($payload);
    }

    /** @return array{price_id: int, provider: string, issued_at: int, nonce: string, key_id: string, source: string, campaign: string, return_url: string|null} */
    public function revalidate(array $payload): array
    {
        return $this->assertAcceptable($this->validatedPayload($payload));
    }

    /** @param array{issued_at?: mixed, nonce?: mixed, key_id?: mixed} $payload */
    public function reserve(array $payload, int $teamId, int $userId): bool
    {
        $ttl = max(60, (int) config('oneploy.wordpress_bridge.ttl_seconds', 900));
        $remainingSeconds = ((int) ($payload['issued_at'] ?? 0) + $ttl) - now()->timestamp;
        if ($remainingSeconds < 1) {
            return false;
        }

        $reservationKey = 'oneploy:wordpress-handoff:'.hash(
            'sha256',
            (string) ($payload['key_id'] ?? '')."\0".(string) ($payload['nonce'] ?? ''),
        );
        $owner = hash('sha256', $teamId."\0".$userId);
        if (Cache::add($reservationKey, $owner, $remainingSeconds)) {
            return true;
        }

        $existingOwner = Cache::get($reservationKey);

        return is_string($existingOwner) && hash_equals($owner, $existingOwner);
    }

    /** @return array{price_id: int, provider: string, issued_at: int, nonce: string, key_id: string, source: string, campaign: string, return_url: string|null} */
    private function validatedPayload(array $input, bool $requireSignature = false): array
    {
        $rules = [
            'price_id' => ['required', 'integer', 'min:1'],
            'provider' => ['required', 'in:paypal,stripe,razorpay'],
            'issued_at' => ['required', 'integer', 'min:1'],
            'nonce' => ['required', 'string', 'min:16', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'key_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'source' => ['required', 'in:wordpress'],
            'campaign' => ['nullable', 'string', 'max:100'],
            'return_url' => ['nullable', 'url:https', 'max:2048'],
        ];
        if ($requireSignature) {
            $rules['signature'] = ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'];
        }

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new InvalidArgumentException('The WordPress checkout handoff is invalid.');
        }

        $validated = $validator->validated();

        return [
            'price_id' => (int) $validated['price_id'],
            'provider' => strtolower((string) $validated['provider']),
            'issued_at' => (int) $validated['issued_at'],
            'nonce' => (string) $validated['nonce'],
            'key_id' => (string) $validated['key_id'],
            'source' => (string) $validated['source'],
            'campaign' => (string) ($validated['campaign'] ?? ''),
            'return_url' => filled($validated['return_url'] ?? null) ? (string) $validated['return_url'] : null,
        ];
    }

    /** @param array{price_id: int, provider: string, issued_at: int, nonce: string, key_id: string, source: string, campaign: string, return_url: string|null} $payload
     * @return array{price_id: int, provider: string, issued_at: int, nonce: string, key_id: string, source: string, campaign: string, return_url: string|null}
     */
    private function assertAcceptable(array $payload): array
    {
        $ttl = max(60, (int) config('oneploy.wordpress_bridge.ttl_seconds', 900));
        if ($payload['issued_at'] < now()->subSeconds($ttl)->timestamp || $payload['issued_at'] > now()->addMinute()->timestamp) {
            throw new InvalidArgumentException('The WordPress checkout handoff has expired.');
        }

        if (! hash_equals((string) config('oneploy.wordpress_bridge.key_id', 'default'), $payload['key_id'])) {
            throw new InvalidArgumentException('The WordPress checkout handoff key is invalid.');
        }

        if ($payload['return_url'] !== null && ! $this->returnUrlAllowed($payload['return_url'])) {
            throw new InvalidArgumentException('The WordPress checkout return URL is not allowed.');
        }

        if ($this->activePrice($payload['price_id']) === null) {
            throw new InvalidArgumentException('The selected WordPress checkout price is unavailable.');
        }

        return $payload;
    }

    public function activePrice(int $priceId): ?OneployPrice
    {
        $effectiveAt = now();

        return OneployPrice::query()
            ->with('planVersion.plan.product')
            ->whereKey($priceId)
            ->where('status', 'active')
            ->effectiveAt($effectiveAt)
            ->whereHas('planVersion', fn ($query) => $query
                ->where('status', 'published')
                ->effectiveAt($effectiveAt))
            ->whereHas('planVersion.plan', fn ($query) => $query->where('is_active', true))
            ->whereHas('planVersion.plan.product', fn ($query) => $query->where('is_active', true))
            ->first();
    }

    private function returnUrlAllowed(string $returnUrl): bool
    {
        $marketingUrl = (string) config('oneploy.wordpress_bridge.marketing_url');
        if ($marketingUrl === '') {
            return false;
        }

        return $this->origin($returnUrl) !== null
            && hash_equals((string) $this->origin($marketingUrl), (string) $this->origin($returnUrl));
    }

    private function origin(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        if ($scheme !== 'https' || $host === '') {
            return null;
        }

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }
}
