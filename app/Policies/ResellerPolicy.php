<?php

namespace App\Policies;

use App\Models\Reseller;
use App\Models\User;

class ResellerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || filled($user->reseller);
    }

    public function view(User $user, Reseller $reseller): bool
    {
        return $user->isSuperAdmin() || $user->reseller?->id === $reseller->id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Name, description, pricing and default plan of the reseller.
     */
    public function update(User $user, Reseller $reseller): bool
    {
        return $user->isSuperAdmin() || ($user->reseller?->id === $reseller->id && $reseller->isActive());
    }

    /**
     * Aggregate quotas and lifecycle status are platform-operator only: a reseller
     * must never be able to raise its own limits or lift its own suspension.
     */
    public function manageQuota(User $user, Reseller $reseller): bool
    {
        return $user->isSuperAdmin();
    }

    public function manageStatus(User $user, Reseller $reseller): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Reseller $reseller): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Create and administer tenants under this reseller.
     */
    public function manageTenants(User $user, Reseller $reseller): bool
    {
        return $user->isSuperAdmin() || ($user->reseller?->id === $reseller->id && $reseller->isActive());
    }
}
