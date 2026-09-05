<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Validation\ValidationException;

class CannedResponseRenderer
{
    /** @var list<string> */
    public const PLACEHOLDERS = [
        'customer.name',
        'ticket.ticket_number',
        'ticket.subject',
        'department.name',
        'assignee.name',
        'current_date',
    ];

    /** @return list<string> */
    public function unknownPlaceholders(string $template): array
    {
        preg_match_all('/{{\s*([a-z_]+(?:\.[a-z_]+)*)\s*}}/i', $template, $matches);
        $found = array_values(array_unique($matches[1]));

        return array_values(array_diff($found, self::PLACEHOLDERS));
    }

    public function render(string $template, Ticket $ticket): string
    {
        $unknown = $this->unknownPlaceholders($template);
        if ($unknown !== []) {
            $formatted = collect($unknown)->map(fn (string $name): string => "{{{$name}}}")->implode(', ');
            throw ValidationException::withMessages([
                'body' => "Unknown placeholder(s): {$formatted}.",
            ]);
        }

        $ticket->loadMissing(['customer', 'department', 'assignee']);
        $customer = $ticket->customer;
        $department = $ticket->department;
        $assignee = $ticket->assignee;
        $context = [
            'customer.name' => $customer->name ?? 'Customer',
            'ticket.ticket_number' => $ticket->ticket_number,
            'ticket.subject' => $ticket->subject,
            'department.name' => $department->name ?? 'No department',
            'assignee.name' => $assignee->name ?? 'Unassigned',
            'current_date' => now()->toFormattedDateString(),
        ];

        return (string) preg_replace_callback(
            '/{{\s*([a-z_]+(?:\.[a-z_]+)*)\s*}}/i',
            fn (array $match): string => $context[$match[1]],
            $template,
        );
    }
}
