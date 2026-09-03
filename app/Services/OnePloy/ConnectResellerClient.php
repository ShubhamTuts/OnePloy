<?php

namespace App\Services\OnePloy;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ConnectResellerClient
{
    public function isConfigured(): bool
    {
        return filled(config('oneploy.domains.connectreseller_api_url'))
            && filled(config('oneploy.domains.connectreseller_api_key'));
    }

    /** @return array{domain: string, available: ?bool, premium: bool, source: string, message: ?string} */
    public function availability(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if (! $this->isConfigured()) {
            return [
                'domain' => $domain,
                'available' => null,
                'premium' => false,
                'source' => 'unconfigured',
                'message' => 'ConnectReseller API key is not configured.',
            ];
        }

        $payload = $this->get('/checkdomainavailable', [
            'websiteName' => $domain,
        ], retry: true);
        $providerStatus = $this->providerStatus($payload);
        $rawAvailable = data_get($payload, 'responseData.available')
            ?? data_get($payload, 'available');
        $available = $this->booleanValue($rawAvailable);

        if ($available === null && $providerStatus !== null) {
            $available = $providerStatus === 200;
        }

        return [
            'domain' => $domain,
            'available' => $available,
            'premium' => $this->booleanValue(
                data_get($payload, 'responseData.isPremium')
                    ?? data_get($payload, 'responseData.premium')
                    ?? false
            ) === true,
            'source' => 'connectreseller',
            'message' => $this->providerMessage($payload),
        ];
    }

    /**
     * @param  list<string>  $nameservers
     * @param  array{name: string, email: string, company: string, address: string, city: string, state: string, country: string, postal_code: string, phone_country_code: string, phone: string}  $registrant
     * @return array{provider_reference: string, created_at: ?string, expires_at: ?string, customer_id: int}
     */
    public function register(
        string $domain,
        int $years,
        bool $privacy,
        array $nameservers,
        array $registrant,
    ): array {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ConnectReseller is not configured.');
        }
        if (count($nameservers) < 2) {
            throw new RuntimeException('ConnectReseller registration requires at least two nameservers.');
        }

        $existingRegistration = $this->existingRegistration($domain);
        if ($existingRegistration) {
            return $existingRegistration;
        }

        $customerId = $this->customerId($registrant);
        $parameters = [
            'ProductType' => 1,
            'WebsiteName' => strtolower(trim($domain)),
            'Duration' => $years,
            'IsWhoisProtection' => $privacy ? 'true' : 'false',
            'ns1' => $nameservers[0],
            'ns2' => $nameservers[1],
            'Id' => $customerId,
            'isEnablePremium' => 0,
        ];
        foreach (array_slice($nameservers, 2, 2) as $index => $nameserver) {
            $parameters['ns'.($index + 3)] = $nameserver;
        }

        // Domain registration is a paid, non-idempotent registrar operation.
        // Never automatically retry this request after a network timeout.
        $payload = $this->get('/domainorder', $parameters, retry: false);
        $this->assertProviderSuccess($payload, 'Domain registration');

        $providerReference = data_get($payload, 'responseData.orderId')
            ?? data_get($payload, 'responseData.domainId')
            ?? data_get($payload, 'responseData.id')
            ?? data_get($payload, 'responseMsg.id')
            ?? data_get($payload, 'responseData.name')
            ?? strtolower(trim($domain));

        return [
            'provider_reference' => (string) $providerReference,
            'created_at' => $this->nullableString(data_get($payload, 'responseData.creationDate')),
            'expires_at' => $this->nullableString(data_get($payload, 'responseData.expiryDate')),
            'customer_id' => $customerId,
        ];
    }

    /** @return array{provider_reference: string, created_at: ?string, expires_at: ?string, customer_id: int}|null */
    private function existingRegistration(string $domain): ?array
    {
        $payload = $this->get('/ViewDomain', [
            'websiteName' => strtolower(trim($domain)),
        ], retry: true);
        if ($this->providerStatus($payload) !== 200) {
            return null;
        }

        $providerReference = data_get($payload, 'responseData.domainNameId')
            ?? data_get($payload, 'responseData.websiteId')
            ?? data_get($payload, 'responseData.id')
            ?? data_get($payload, 'responseMsg.id')
            ?? strtolower(trim($domain));

        return [
            'provider_reference' => (string) $providerReference,
            'created_at' => $this->nullableString(data_get($payload, 'responseData.creationDate')),
            'expires_at' => $this->nullableString(
                data_get($payload, 'responseData.expirationDate')
                    ?? data_get($payload, 'responseData.expiryDate')
            ),
            'customer_id' => (int) (data_get($payload, 'responseData.customerId') ?? 0),
        ];
    }

    public function suggest(string $query): array
    {
        return [
            'query' => $query,
            'suggestions' => [
                Str::slug($query).'.com',
                Str::slug($query).'.net',
                Str::slug($query).'.in',
            ],
        ];
    }

    /**
     * @param  array{name: string, email: string, company: string, address: string, city: string, state: string, country: string, postal_code: string, phone_country_code: string, phone: string}  $registrant
     */
    private function customerId(array $registrant): int
    {
        $existing = $this->get('/ViewClient', [
            'UserName' => $registrant['email'],
        ], retry: true);
        $customerId = $this->extractCustomerId($existing);
        if ($customerId) {
            return $customerId;
        }

        $created = $this->get('/AddClient', [
            'FirstName' => $registrant['name'],
            'UserName' => $registrant['email'],
            'Password' => Str::password(32),
            'CompanyName' => $registrant['company'],
            'Address1' => $registrant['address'],
            'City' => $registrant['city'],
            'StateName' => $registrant['state'],
            'CountryName' => $registrant['country'],
            'Zip' => $registrant['postal_code'],
            'PhoneNo_cc' => ltrim($registrant['phone_country_code'], '+'),
            'PhoneNo' => $registrant['phone'],
        ], retry: false);
        $this->assertProviderSuccess($created, 'Customer creation');

        $customerId = $this->extractCustomerId($created);
        if ($customerId) {
            return $customerId;
        }

        $resolved = $this->get('/ViewClient', [
            'UserName' => $registrant['email'],
        ], retry: true);
        $customerId = $this->extractCustomerId($resolved);
        if (! $customerId) {
            throw new RuntimeException('ConnectReseller created the customer but did not return a customer ID.');
        }

        return $customerId;
    }

    /** @param array<string, mixed> $payload */
    private function extractCustomerId(array $payload): ?int
    {
        if ($this->providerStatus($payload) !== 200) {
            return null;
        }

        $value = data_get($payload, 'responseData.clientId')
            ?? data_get($payload, 'responseData.customerId')
            ?? data_get($payload, 'responseData.id')
            ?? data_get($payload, 'responseMsg.id')
            ?? data_get($payload, 'clientId');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $parameters, bool $retry): array
    {
        $request = $this->request();
        if ($retry) {
            $request->retry([200, 500, 1000], throw: false);
        }

        $response = $request->get($path, [
            'APIKey' => (string) config('oneploy.domains.connectreseller_api_key'),
            ...$parameters,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('ConnectReseller request failed with HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('ConnectReseller returned an invalid response.');
        }

        return $payload;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('oneploy.domains.connectreseller_api_url'), '/'))
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(5);
    }

    /** @param array<string, mixed> $payload */
    private function assertProviderSuccess(array $payload, string $operation): void
    {
        $status = $this->providerStatus($payload);
        if ($status === 200) {
            return;
        }

        $message = $this->providerMessage($payload);
        throw new RuntimeException($operation.' was rejected by ConnectReseller'.($message ? ': '.$message : '.'));
    }

    /** @param array<string, mixed> $payload */
    private function providerStatus(array $payload): ?int
    {
        $status = data_get($payload, 'responseMsg.statusCode')
            ?? data_get($payload, 'responseData.statusCode')
            ?? data_get($payload, 'statusCode');

        return is_numeric($status) ? (int) $status : null;
    }

    /** @param array<string, mixed> $payload */
    private function providerMessage(array $payload): ?string
    {
        $message = data_get($payload, 'responseMsg.message')
            ?? data_get($payload, 'responseData.message')
            ?? data_get($payload, 'message');

        return is_string($message) && $message !== '' ? Str::limit(strip_tags($message), 240) : null;
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }
        if (is_string($value)) {
            return match (strtolower($value)) {
                'true', '1', 'yes', 'available' => true,
                'false', '0', 'no', 'unavailable' => false,
                default => null,
            };
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
