<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Pending => 'Pending',
            self::OnHold => 'On Hold',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => '#22c55e',
            self::Pending => '#f59e0b',
            self::OnHold => '#6b7280',
            self::Resolved => '#3b82f6',
            self::Closed => '#9ca3af',
        };
    }

    public static function pausedStatuses(): array
    {
        return [self::Pending, self::OnHold];
    }
}
