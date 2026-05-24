<?php

namespace App\Support;

class TicketMessageSenderTypes
{
    public const CUSTOMER = 'customer';

    public const AGENT = 'agent';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::CUSTOMER,
            self::AGENT,
        ];
    }
}
