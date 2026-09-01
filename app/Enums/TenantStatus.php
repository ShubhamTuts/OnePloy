<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    /**
     * Whether workloads may be created, deployed or started.
     */
    public function allowsWorkloads(): bool
    {
        return $this === self::Active;
    }
}
