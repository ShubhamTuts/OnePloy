<?php

namespace App\Enums;

enum Role: string
{
    case MEMBER = 'member';
    case DEPLOYER = 'deployer';
    case ADMIN = 'admin';
    case OWNER = 'owner';

    public function rank(): int
    {
        return match ($this) {
            self::MEMBER => 1,
            self::DEPLOYER => 2,
            self::ADMIN => 3,
            self::OWNER => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MEMBER => 'Viewer',
            self::DEPLOYER => 'Deployer',
            self::ADMIN => 'Admin',
            self::OWNER => 'Owner',
        };
    }

    public function lt(Role|string $role): bool
    {
        if (is_string($role)) {
            $role = Role::from($role);
        }

        return $this->rank() < $role->rank();
    }

    public function gt(Role|string $role): bool
    {
        if (is_string($role)) {
            $role = Role::from($role);
        }

        return $this->rank() > $role->rank();
    }

    public function gte(Role|string $role): bool
    {
        if (is_string($role)) {
            $role = Role::from($role);
        }

        return $this->rank() >= $role->rank();
    }
}
