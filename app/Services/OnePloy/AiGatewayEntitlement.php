<?php

namespace App\Services\OnePloy;

use App\Exceptions\OneployAiGatewayException;
use App\Models\OneployCommerceSubscription;
use App\Models\Team;

class AiGatewayEntitlement
{
    public function monthlyTokenLimit(Team $team): ?int
    {
        if (! $team->isTenantActive()) {
            throw new OneployAiGatewayException(
                'tenant_inactive',
                403,
                'This tenant is not allowed to use the AI Gateway.',
            );
        }

        if ($team->isPlatformTeam()) {
            return null;
        }

        $subscription = OneployCommerceSubscription::query()
            ->forTeam($team)
            ->forProductFamily('ai_gateway')
            ->eligible()
            ->latest('id')
            ->first();
        $entitlements = $subscription?->entitlement_snapshot;

        if (! is_array($entitlements) || ($entitlements['ai_gateway.enabled'] ?? false) !== true) {
            throw new OneployAiGatewayException(
                'gateway_not_entitled',
                403,
                'An active AI Gateway subscription is required.',
            );
        }

        $limit = $entitlements['ai.tokens.monthly'] ?? null;
        if (! is_int($limit) || $limit < 0) {
            throw new OneployAiGatewayException(
                'invalid_gateway_entitlement',
                503,
                'The AI Gateway subscription is not configured correctly.',
            );
        }

        return $limit;
    }
}
