<?php

namespace App\Support;

class TicketStatuses
{
    public const OPEN = 'open';

    public const PENDING = 'pending';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::OPEN,
            self::PENDING,
            self::RESOLVED,
            self::CLOSED,
        ];
    }
}
