<?php

use App\Models\User;
use App\Services\Auth\StaffAccountLifecycleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

test('inactive users cannot password login', function () {
    $user = User::factory()->create([
        'email' => 'inactive@example.com',
        'password' => bcrypt('password'),
        'is_active' => false,
    ]);

    post(route('login'), [
        'email' => 'inactive@example.com',
        'password' => 'password',
        'remember' => true,
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('deactivating an existing session blocks staff routes', function () {
    $user = User::factory()->create([
        'email' => 'agent@example.com',
        'password' => bcrypt('password'),
    ]);

    post(route('login'), [
        'email' => 'agent@example.com',
        'password' => 'password',
    ])->assertRedirect(route('agent.dashboard'));

    User::whereKey($user->id)->update(['is_active' => false]);
    Auth::forgetGuards();

    get(route('agent.dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});

test('deactivating a remembered user revokes remembered access', function () {
    $user = User::factory()->create([
        'email' => 'remembered@example.com',
        'password' => bcrypt('password'),
    ]);

    $response = post(route('login'), [
        'email' => 'remembered@example.com',
        'password' => 'password',
        'remember' => true,
    ])->assertRedirect(route('agent.dashboard'));

    $recallerName = Auth::guard('web')->getRecallerName();
    $recaller = $response->getCookie($recallerName)->getValue();
    $rememberToken = $user->fresh()->getRememberToken();

    session()->forget(Auth::guard('web')->getName());
    User::whereKey($user->id)->update(['is_active' => false]);
    Auth::forgetGuards();

    $this->withCookie($recallerName, $recaller);

    get(route('agent.dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
    expect($user->fresh()->getRememberToken())->not->toBe($rememberToken);
});

test('a dormant database session cannot revive after deactivation and reactivation', function () {
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();

    $user = User::factory()->create([
        'email' => 'dormant-session@example.com',
        'password' => bcrypt('password'),
    ]);
    $sessionCookieName = (string) config('session.cookie');
    $loginResponse = post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('agent.dashboard'));
    $capturedSession = $loginResponse->getCookie($sessionCookieName)?->getValue();

    expect($capturedSession)->not->toBeNull()
        ->and(DB::table('sessions')->where('id', $capturedSession)->exists())->toBeTrue();

    app(StaffAccountLifecycleService::class)->update($user, ['is_active' => false]);
    app(StaffAccountLifecycleService::class)->update($user->fresh(), ['is_active' => true]);

    expect(DB::table('sessions')->where('id', $capturedSession)->exists())->toBeFalse();

    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
    $this->withCookie($sessionCookieName, $capturedSession);

    get(route('agent.dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a dormant remember cookie cannot revive after deactivation and reactivation', function () {
    $user = User::factory()->create([
        'email' => 'dormant-recaller@example.com',
        'password' => bcrypt('password'),
    ]);
    $loginResponse = post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ])->assertRedirect(route('agent.dashboard'));
    $recallerName = Auth::guard('web')->getRecallerName();
    $capturedRecaller = $loginResponse->getCookie($recallerName)?->getValue();
    $originalRememberToken = $user->fresh()->getRememberToken();

    expect($capturedRecaller)->not->toBeNull();

    app(StaffAccountLifecycleService::class)->update($user, ['is_active' => false]);
    app(StaffAccountLifecycleService::class)->update($user->fresh(), ['is_active' => true]);

    expect($user->fresh()->getRememberToken())->not->toBe($originalRememberToken);

    session()->forget(Auth::guard('web')->getName());
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
    $this->withCookie($recallerName, $capturedRecaller);

    get(route('agent.dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('deactivating an existing session blocks profile mutations', function () {
    $user = User::factory()->create([
        'email' => 'agent@example.com',
        'password' => bcrypt('password'),
    ]);

    post(route('login'), [
        'email' => 'agent@example.com',
        'password' => 'password',
    ]);

    User::whereKey($user->id)->update(['is_active' => false]);
    Auth::forgetGuards();

    $this->patch(route('profile.update'), [
        'name' => 'Changed Name',
        'email' => $user->email,
    ])->assertRedirect(route('login'));

    expect($user->fresh()->name)->not->toBe('Changed Name');
    $this->assertGuest();
});

test('inactive admins cannot reactivate their own account', function () {
    $admin = User::factory()->admin()->create(['is_active' => false])->fresh();

    actingAs($admin);

    put(route('settings.users.update', $admin), [
        'is_active' => true,
    ])->assertRedirect(route('login'));

    expect($admin->fresh()->is_active)->toBeFalse();
    $this->assertGuest();
});

test('active admins can reactivate another account', function () {
    $admin = User::factory()->admin()->create()->fresh();
    $inactiveUser = User::factory()->create(['is_active' => false]);

    actingAs($admin);

    put(route('settings.users.update', $inactiveUser), [
        'is_active' => true,
    ])->assertRedirect();

    expect($inactiveUser->fresh()->is_active)->toBeTrue();
});

test('inactive users can still use the normal logout endpoint', function () {
    $user = User::factory()->create(['is_active' => false]);

    actingAs($user);

    post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
});

test('active middleware covers staff routes without affecting customer routes', function () {
    $routes = collect(Route::getRoutes()->getRoutes());

    $staffRoutes = $routes->filter(fn ($route) => str_starts_with($route->uri(), 'agent/')
        || $route->uri() === 'agent'
        || str_starts_with($route->uri(), 'settings/')
        || $route->uri() === 'profile'
        || str_starts_with($route->uri(), 'verify-email')
        || $route->uri() === 'email/verification-notification'
        || $route->uri() === 'confirm-password'
        || $route->uri() === 'password');

    expect($staffRoutes)->not->toBeEmpty();

    $staffRoutes->each(function ($route) {
        expect($route->gatherMiddleware())->toContain('active');
    });

    $logout = $routes->first(fn ($route) => $route->getName() === 'logout');
    expect($logout->gatherMiddleware())->not->toContain('active');

    $customerRoutes = $routes->filter(fn ($route) => str_starts_with((string) $route->getName(), 'customer.'));
    $customerRoutes->each(function ($route) {
        expect($route->gatherMiddleware())->not->toContain('active');
    });
});
