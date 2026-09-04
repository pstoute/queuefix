<?php

namespace App\Enums;

enum TicketActivityActorType: string
{
    case User = 'user';
    case Customer = 'customer';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Customer => 'Customer',
            self::System => 'System',
        };
    }
}
