<?php

namespace App\Enums;

/**
 * Platform level of an actor, above and including the team (tenant) boundary.
 *
 * SUB_USER and TENANT are both backed by a team membership (team_user.role);
 * RESELLER and SUPER_ADMIN live outside the team boundary.
 */
enum PlatformRole: string
{
    case SubUser = 'sub_user';
    case Tenant = 'tenant';
    case Reseller = 'reseller';
    case SuperAdmin = 'super_admin';

    public function rank(): int
    {
        return match ($this) {
            self::SubUser => 1,
            self::Tenant => 2,
            self::Reseller => 3,
            self::SuperAdmin => 4,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::SubUser => 'Sub-user',
            self::Tenant => 'Tenant',
            self::Reseller => 'Reseller',
            self::SuperAdmin => 'Super Admin',
        };
    }
}
