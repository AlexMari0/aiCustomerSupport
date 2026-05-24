<?php

namespace App\Support;

class TicketPriorities
{
    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    public const URGENT = 'urgent';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::LOW,
            self::MEDIUM,
            self::HIGH,
            self::URGENT,
        ];
    }
}
