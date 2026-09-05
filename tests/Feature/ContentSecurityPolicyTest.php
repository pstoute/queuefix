<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('agent and customer ticket views restrict active message content', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);

    actingAs(User::factory()->create());
    get(route('agent.tickets.show', $ticket))
        ->assertOk()
        ->assertHeader('content-security-policy', AddContentSecurityPolicy::POLICY);

    actingAs($customer, 'customer');
    get(route('customer.tickets.show', $ticket))
        ->assertOk()
        ->assertHeader('content-security-policy', AddContentSecurityPolicy::POLICY);
});
