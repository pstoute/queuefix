<?php

use App\Models\User;
use App\Services\Auth\StaffPasswordLoginRateLimiter;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ViewErrorBag;

use function Pest\Laravel\post;

function staffPasswordLoginError(string $email, string $password, string $source): string
{
    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => $source])
        ->post(route('login'), [
            'email' => $email,
            'password' => $password,
        ]);

    $response->assertSessionHasErrors('email');

    $errors = $response->getSession()->get('errors');

    expect($errors)->toBeInstanceOf(ViewErrorBag::class);

    return $errors->getBag('default')->first('email');
}

test('staff password login is limited per account across source addresses', function () {
    Event::fake([Lockout::class]);

    $user = User::factory()->create([
        'email' => 'target@example.com',
        'password' => 'correct-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        staffPasswordLoginError('target@example.com', 'wrong-password', "192.0.2.{$attempt}");
    }

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])
        ->post(route('login'), [
            'email' => 'target@example.com',
            'password' => 'correct-password',
        ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
    Event::assertDispatched(Lockout::class);
    expect($user->fresh())->not->toBeNull();
});

test('known and unknown identities receive the same distributed throttle response', function () {
    $this->freezeTime();

    User::factory()->create([
        'email' => 'known@example.com',
        'password' => 'correct-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        staffPasswordLoginError('known@example.com', 'wrong-password', "192.0.2.{$attempt}");
        staffPasswordLoginError('missing@example.com', 'wrong-password', "198.51.100.{$attempt}");
    }

    $knownError = staffPasswordLoginError('known@example.com', 'wrong-password', '192.0.2.6');
    $unknownError = staffPasswordLoginError('missing@example.com', 'wrong-password', '198.51.100.6');

    expect($knownError)
        ->toBe($unknownError)
        ->toStartWith('Too many login attempts.');
    $this->assertDatabaseMissing('users', ['email' => 'missing@example.com']);
    $this->assertGuest();
});

test('case and whitespace variants share one staff password account budget', function () {
    User::factory()->create([
        'email' => 'target@example.com',
        'password' => 'correct-password',
    ]);

    $variants = [
        'TARGET@example.com',
        ' target@example.com ',
        'Target@Example.Com',
        'TARGET@EXAMPLE.COM',
        'target@example.com',
    ];

    foreach ($variants as $index => $email) {
        staffPasswordLoginError($email, 'wrong-password', '192.0.2.'.($index + 1));
    }

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])
        ->post(route('login'), [
            'email' => 'target@example.com',
            'password' => 'correct-password',
        ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('another staff identity remains available after a target is limited', function () {
    User::factory()->create([
        'email' => 'target@example.com',
        'password' => 'correct-password',
    ]);
    $other = User::factory()->create([
        'email' => 'other@example.com',
        'password' => 'other-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        staffPasswordLoginError('target@example.com', 'wrong-password', "192.0.2.{$attempt}");
    }

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])
        ->post(route('login'), [
            'email' => 'other@example.com',
            'password' => 'other-password',
        ])
        ->assertRedirect(route('agent.dashboard'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($other);
});

test('successful staff password login clears only its current budgets', function () {
    $user = User::factory()->create([
        'email' => 'target@example.com',
        'password' => 'correct-password',
    ]);

    foreach (range(1, 4) as $attempt) {
        staffPasswordLoginError('target@example.com', 'wrong-password', '192.0.2.10');
    }

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
        ->post(route('login'), [
            'email' => 'target@example.com',
            'password' => 'correct-password',
        ])
        ->assertRedirect(route('agent.dashboard'));

    post(route('logout'))->assertRedirect('/');

    expect(staffPasswordLoginError('target@example.com', 'wrong-password', '192.0.2.11'))
        ->toBe(trans('auth.failed'));
    expect(staffPasswordLoginError('target@example.com', 'wrong-password', '192.0.2.12'))
        ->toBe(trans('auth.failed'));
    expect(staffPasswordLoginError('target@example.com', 'wrong-password', '192.0.2.10'))
        ->toBe(trans('auth.failed'));

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->post(route('login'), [
            'email' => 'target@example.com',
            'password' => 'correct-password',
        ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($user->fresh())->not->toBeNull();
});

test('staff password account cooldown expires after one minute', function () {
    $user = User::factory()->create([
        'email' => 'target@example.com',
        'password' => 'correct-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        staffPasswordLoginError('target@example.com', 'wrong-password', "192.0.2.{$attempt}");
    }

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])
        ->post(route('login'), [
            'email' => 'target@example.com',
            'password' => 'correct-password',
        ])
        ->assertSessionHasErrors('email');

    $this->travel(61)->seconds();

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.7'])
        ->post(route('login'), [
            'email' => 'target@example.com',
            'password' => 'correct-password',
        ])
        ->assertRedirect(route('agent.dashboard'))
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('trusted forwarded source rotation cannot reset the staff account budget', function () {
    User::factory()->create([
        'email' => 'target@example.com',
        'password' => 'correct-password',
    ]);
    TrustProxies::at(['198.51.100.40']);

    try {
        foreach (range(1, 5) as $attempt) {
            $response = $this
                ->withHeaders(['X-Forwarded-For' => "203.0.113.{$attempt}"])
                ->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
                ->post(route('login'), [
                    'email' => 'target@example.com',
                    'password' => 'wrong-password',
                ]);

            $response->assertSessionHasErrors('email');
        }

        $this
            ->withHeaders(['X-Forwarded-For' => '203.0.113.6'])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
            ->post(route('login'), [
                'email' => 'target@example.com',
                'password' => 'correct-password',
            ])
            ->assertSessionHasErrors('email');
    } finally {
        TrustProxies::at([]);
    }

    $this->assertGuest();
});

test('staff password limiter keys are private canonical and source scoped', function () {
    $limiter = app(StaffPasswordLoginRateLimiter::class);
    [$firstSourceKey, $firstIdentityKey, $firstAccountKey] = $limiter->keys(
        ' Target@Example.com ',
        '192.0.2.1',
        null,
    );
    [$secondSourceKey, $secondIdentityKey, $secondAccountKey] = $limiter->keys(
        'target@example.COM',
        '192.0.2.2',
        null,
    );

    expect($firstIdentityKey)
        ->toBe($secondIdentityKey)
        ->not->toContain('target@example.com')
        ->and($firstAccountKey)
        ->toBe($secondAccountKey)
        ->not->toContain('target@example.com')
        ->and($firstSourceKey)
        ->not->toBe($secondSourceKey)
        ->not->toContain('target@example.com')
        ->not->toContain('192.0.2.1')
        ->and($limiter->keys('ae@example.com', '192.0.2.1', null)[1])
        ->not->toBe($limiter->keys('æ@example.com', '192.0.2.1', null)[1])
        ->and($limiter->keys('ss@example.com', '192.0.2.1', 'App\\Models\\User:staff-id')[2])
        ->toBe($limiter->keys('ß@example.com', '192.0.2.2', 'App\\Models\\User:staff-id')[2]);
});

test('database backed limiter instances share the atomic staff account budget', function () {
    $first = new StaffPasswordLoginRateLimiter(new RateLimiter(Cache::store('database')));
    $second = new StaffPasswordLoginRateLimiter(new RateLimiter(Cache::store('database')));

    foreach (range(1, 5) as $attempt) {
        $limiter = $attempt % 2 === 0 ? $second : $first;

        expect($limiter->reserve('shared@example.com', "192.0.2.{$attempt}", null))->toBeNull();
    }

    expect($second->reserve('SHARED@example.com', '192.0.2.6', null))->toBeGreaterThan(0);
});

test('a concurrent threshold crossing is rejected before password verification', function () {
    $atomicLimiter = new class(Cache::store('array')) extends RateLimiter
    {
        /** @var list<int> */
        public array $hitResults = [5, 5, 6];

        public int $hits = 0;

        public function tooManyAttempts($key, $maxAttempts): bool
        {
            return false;
        }

        public function hit($key, $decaySeconds = 60): int
        {
            $this->hits++;

            return array_shift($this->hitResults);
        }

        public function availableIn($key): int
        {
            return 60;
        }
    };
    $limiter = new StaffPasswordLoginRateLimiter($atomicLimiter);

    expect($limiter->reserve('target@example.com', '192.0.2.1', 'App\\Models\\User:staff-id'))
        ->toBe(60)
        ->and($atomicLimiter->hits)
        ->toBe(3);
});

test('a failed limiter increment rejects the password attempt', function () {
    $failingLimiter = new class(Cache::store('array')) extends RateLimiter
    {
        /** @var list<int> */
        public array $hitResults = [1, 0, 1];

        public int $hits = 0;

        public function tooManyAttempts($key, $maxAttempts): bool
        {
            return false;
        }

        public function hit($key, $decaySeconds = 60): int
        {
            $this->hits++;

            return array_shift($this->hitResults);
        }

        public function availableIn($key): int
        {
            return 0;
        }
    };
    $limiter = new StaffPasswordLoginRateLimiter($failingLimiter);

    expect($limiter->reserve('target@example.com', '192.0.2.1', 'App\\Models\\User:staff-id'))
        ->toBe(1)
        ->and($failingLimiter->hits)
        ->toBe(3);
});

test('mysql equivalent staff identities share the resolved account budget', function () {
    if ($this->app['db']->connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL collation regression.');
    }

    User::factory()->create([
        'email' => 'ss@example.com',
        'password' => 'correct-password',
    ]);
    $variants = [
        'ss@example.com',
        'ß@example.com',
        'ss@example.com',
        'ß@example.com',
        'ss@example.com',
    ];

    foreach ($variants as $index => $email) {
        staffPasswordLoginError($email, 'wrong-password', '192.0.2.'.($index + 1));
    }

    $this
        ->withServerVariables(['REMOTE_ADDR' => '192.0.2.6'])
        ->post(route('login'), [
            'email' => 'ß@example.com',
            'password' => 'correct-password',
        ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
