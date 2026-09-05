<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $user->is_active && ! $ticket->isMerged();
    }

    public function watch(User $user, Ticket $ticket): bool
    {
        return $user->is_active && ! $ticket->isMerged() && $this->view($user, $ticket);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function merge(User|Customer $user, Ticket $ticket): bool
    {
        return $user instanceof User && $user->is_active;
    }
}
