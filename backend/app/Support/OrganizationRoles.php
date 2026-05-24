<?php

namespace App\Support;

class OrganizationRoles
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const AGENT = 'agent';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::OWNER,
            self::ADMIN,
            self::AGENT,
        ];
    }
}
