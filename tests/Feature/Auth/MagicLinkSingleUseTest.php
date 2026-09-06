<?php

use App\Mail\CustomerMagicLinkMail;
use App\Mail\MagicLinkMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\MagicLinkService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

function requestStaffMagicLink(User $user): string
{
    Mail::fake();

    post(route('auth.magic-link.send'), ['email' => $user->email])
        ->assertRedirect()
        ->assertSessionHas('status');

    $url = null;
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use ($user, &$url): bool {
        if ($mail->hasTo($user->email)) {
            $url = $mail->url;

            return true;
        }

        return false;
    });

    expect($url)->toBeString()->not->toBeEmpty();

    return $url;
}

function requestCustomerMagicLink(Customer $customer): string
{
    Mail::fake();

    post(route('customer.login.send'), ['email' => $customer->email])
        ->assertRedirect()
        ->assertSessionHas('status');

    $url = null;
    Mail::assertSent(CustomerMagicLinkMail::class, function (CustomerMagicLinkMail $mail) use ($customer, &$url): bool {
        if ($mail->hasTo($customer->email)) {
            $url = $mail->url;

            return true;
        }

        return false;
    });

    expect($url)->toBeString()->not->toBeEmpty();

    return $url;
}

function magicLinkToken(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) ($query['token'] ?? '');
}

test('a staff magic link is stored as a hash and can be redeemed only once', function () {
    $user = User::factory()->create();
    $url = requestStaffMagicLink($user);
    $token = magicLinkToken($url);

    expect($token)->toMatch('/\A[0-9a-f]{64}\z/D');

    $record = DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->first();
    expect($record)->not->toBeNull()
        ->and($record->guard)->toBe('web')
        ->and($record->purpose)->toBe('login')
        ->and($record->token_hash)->toBe(hash('sha256', $token))
        ->and($record->token_hash)->not->toBe($token)
        ->and($record->consumed_at)->toBeNull();

    get($url)->assertRedirect(route('agent.tickets.index'));
    $this->assertAuthenticatedAs($user);

    Auth::logout();
    session()->invalidate();
    Auth::forgetGuards();

    get($url)
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'This magic link has expired or is invalid.');

    $this->assertGuest();
    expect(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->value('consumed_at'))
        ->not->toBeNull();
});

test('a customer magic link can be redeemed only once', function () {
    $customer = Customer::factory()->create();
    $url = requestCustomerMagicLink($customer);

    get($url)->assertRedirect(route('customer.tickets.index'));
    expect(Auth::guard('customer')->id())->toBe($customer->id);

    Auth::guard('customer')->logout();
    session()->invalidate();
    Auth::forgetGuards();

    get($url)
        ->assertRedirect(route('customer.login'))
        ->assertSessionHas('error', 'This link has expired or is invalid.');

    expect(Auth::guard('customer')->check())->toBeFalse();
});

test('resending a staff magic link invalidates the previous link', function () {
    $user = User::factory()->create();
    $firstUrl = requestStaffMagicLink($user);
    $secondUrl = requestStaffMagicLink($user);

    expect(magicLinkToken($secondUrl))->not->toBe(magicLinkToken($firstUrl))
        ->and(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->count())->toBe(1);

    get($firstUrl)->assertRedirect(route('login'));
    $this->assertGuest();

    get($secondUrl)->assertRedirect(route('agent.tickets.index'));
    $this->assertAuthenticatedAs($user);
});

test('tokens are bound to the account and guard', function () {
    $service = app(MagicLinkService::class);
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $customer = Customer::factory()->create(['id' => $firstUser->id]);
    $magicLink = $service->issueStaff($firstUser);

    expect($magicLink)->not->toBeNull()
        ->and($service->consumeStaff($secondUser, $magicLink['token']))->toBeFalse()
        ->and($service->consumeCustomer($customer, $magicLink['token']))->toBeFalse()
        ->and($service->consumeStaff($firstUser, $magicLink['token']))->toBeTrue()
        ->and($service->consumeStaff($firstUser, $magicLink['token']))->toBeFalse();
});

test('database expiry rejects a still correctly shaped token', function () {
    $service = app(MagicLinkService::class);
    $customer = Customer::factory()->create();
    $magicLink = $service->issueCustomer($customer);

    DB::table('magic_link_tokens')
        ->where('authenticatable_id', $customer->id)
        ->update(['expires_at' => now()->subSecond()]);

    expect($service->consumeCustomer($customer, $magicLink['token']))->toBeFalse();
});

test('deactivation invalidates an outstanding staff magic link', function () {
    $service = app(MagicLinkService::class);
    $user = User::factory()->create();
    $magicLink = $service->issueStaff($user);

    expect($magicLink)->not->toBeNull();

    $user->update(['is_active' => false]);

    expect($service->consumeStaff($user, $magicLink['token']))->toBeFalse()
        ->and(DB::table('magic_link_tokens')->where('authenticatable_id', $user->id)->exists())->toBeFalse();
});

test('malformed tokens are rejected without consuming the current link', function () {
    $service = app(MagicLinkService::class);
    $customer = Customer::factory()->create();
    $magicLink = $service->issueCustomer($customer);

    expect($service->consumeCustomer($customer, 'not-a-token'))->toBeFalse()
        ->and($service->consumeCustomer($customer, $magicLink['token']))->toBeTrue();
});
