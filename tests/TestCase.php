<?php

namespace Tests;

use App\Models\TicketStatus;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function ticketStatusAt(int $sortOrder): TicketStatus
    {
        return TicketStatus::query()->where('sort_order', $sortOrder)->firstOrFail();
    }
}
