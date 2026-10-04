<?php

use App\Enums\TeamRole;
use App\Models\DemoSandbox;
use App\Models\Team;
use App\Models\User;
use Illuminate\Hashing\HashManager;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\Sandboxes;

/*
 * "Try the demo" on the hosted demo: POST /demo makes the visitor a sandbox of their own, a copy of Acme and Globex
 * with its own people, and signs them in as its Acme owner on a new session id.
 */

beforeEach(function () {
    config(['demo.hosted' => true]);
});

it('builds a sandbox, signs the visitor in as its Acme owner and opens the Acme board', function () {
    $this->freezeSecond();

    $response = $this->post(route('demo.store'));

    $sandbox = DemoSandbox::sole();
    $acme = Sandboxes::team($sandbox, 'Acme');
    $globex = Sandboxes::team($sandbox, 'Globex');
    $you = Sandboxes::person($sandbox, 'you');

    $response->assertRedirect(route('board', ['current_team' => $acme->slug]));
    $this->assertAuthenticatedAs($you);

    expect($sandbox->visitor_id)->toBe($you->id)
        ->and($sandbox->expires_at->equalTo(now()->addHours(24)))->toBeTrue()
        ->and($sandbox->ip_hash)->toHaveLength(64)->not->toContain('127.0.0.1');

    $people = User::where('demo_sandbox_id', $sandbox->id)->get();

    expect($people->pluck('name')->sort()->values()->all())->toBe(['Gus Novak', 'Marcus Reed', 'Vera Lind', 'You'])
        ->and($people->every(fn (User $user): bool => $user->hasVerifiedEmail()))->toBeTrue()
        ->and($people->every(fn (User $user): bool => Str::endsWith($user->email, '@sandbox.invalid')))->toBeTrue()
        ->and($people->every(fn (User $user): bool => ! Hash::check('password', $user->password)))->toBeTrue()
        ->and($people->every(fn (User $user): bool => $user->personalTeam()?->demo_sandbox_id === $sandbox->id))->toBeTrue();

    expect($you->teamRole($acme))->toBe(TeamRole::Owner)
        ->and(Sandboxes::person($sandbox, 'marcus')->teamRole($acme))->toBe(TeamRole::Member)
        ->and(Sandboxes::person($sandbox, 'marcus')->teamRole($globex))->toBe(TeamRole::Member)
        ->and(Sandboxes::person($sandbox, 'vera')->teamRole($acme))->toBe(TeamRole::Viewer)
        ->and(Sandboxes::person($sandbox, 'gus')->teamRole($globex))->toBe(TeamRole::Owner)
        ->and(Sandboxes::person($sandbox, 'gus')->teamRole($acme))->toBeNull();

    expect($acme->projects()->count())->toBe(3)
        ->and($acme->tasks()->count())->toBe(13)
        ->and($globex->projects()->count())->toBe(2)
        ->and($globex->tasks()->count())->toBe(7)
        ->and(Team::where('demo_sandbox_id', $sandbox->id)->count())->toBe(6)
        ->and($acme->slug)->toMatch('/^acme-[a-z0-9]{6}$/')
        ->and($globex->slug)->toMatch('/^globex-[a-z0-9]{6}$/');

    $this->get(route('board', ['current_team' => $acme->slug]))->assertOk();
});

it('counts due dates from today', function () {
    $this->post(route('demo.store'));

    $acme = Sandboxes::team(DemoSandbox::sole(), 'Acme');

    expect($acme->tasks()->where('title', 'Write the launch announcement')->sole()->due_on->toDateString())->toBe(today()->addDays(2)->toDateString())
        ->and($acme->tasks()->where('title', 'Fix broken links on the pricing page')->sole()->due_on->toDateString())->toBe(today()->subDays(2)->toDateString());
});

it('signs the visitor in on a new session id', function () {
    $response = $this->withCookie((string) config('session.cookie'), Sandboxes::SESSION_ID)->post(route('demo.store'));

    expect(Sandboxes::sessionIdAfter($response))->not->toBeNull()->not->toBe(Sandboxes::SESSION_ID);

    // The control: a request that signs nobody in keeps the browser's session id.
    $control = $this->withCookie((string) config('session.cookie'), Sandboxes::SESSION_ID)->get(route('home'));

    expect(Sandboxes::sessionIdAfter($control))->toBe(Sandboxes::SESSION_ID);
});

it('gives every visitor a sandbox of their own', function () {
    $this->post(route('demo.store'));
    $this->post(route('demo.store'));

    [$first, $second] = DemoSandbox::orderBy('id')->get()->all();

    expect(Sandboxes::team($first, 'Acme')->slug)->not->toBe(Sandboxes::team($second, 'Acme')->slug)
        ->and(Sandboxes::person($first, 'marcus')->email)->not->toBe(Sandboxes::person($second, 'marcus')->email);

    $this->actingAs(Sandboxes::person($second, 'you'))
        ->get(route('board', ['current_team' => Sandboxes::team($first, 'Acme')->slug]))
        ->assertForbidden();
});

it('tries once more with new names when a random slug is already taken', function () {
    Team::create(['name' => 'Acme', 'slug' => 'acme-aaaaaa']);

    $calls = 0;
    Str::createRandomStringsUsing(function (int $length) use (&$calls): string {
        return $length === 6 ? (++$calls <= 10 ? 'aaaaaa' : 'bbbbbb') : str_repeat('x', $length);
    });

    try {
        $this->post(route('demo.store'))->assertRedirect();
    } finally {
        Str::createRandomStringsNormally();
    }

    expect(DemoSandbox::count())->toBe(1)
        ->and(Sandboxes::team(DemoSandbox::sole(), 'Acme')->slug)->toBe('acme-bbbbbb')
        ->and(User::count())->toBe(4);
});

it('answers 503 once as many sandboxes are live as the demo holds', function () {
    config(['demo.max_live' => 2]);

    Sandboxes::start();
    $this->travel(25)->hours();
    Sandboxes::start();

    // One expired sandbox no longer counts, so there is room for one more.
    $this->post(route('demo.store'))->assertRedirect();

    $this->post(route('demo.store'))
        ->assertStatus(503)
        ->assertHeader('Retry-After', '300')
        ->assertSeeText('The demo is full right now. Try again in a few minutes.');

    expect(DemoSandbox::count())->toBe(3);
});

it('allows five sandboxes an hour from one address', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('demo.store'))->assertRedirect();
    }

    $this->post(route('demo.store'))->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->post(route('demo.store'))->assertRedirect();
});

it('allows 120 sandboxes an hour in all', function () {
    foreach (range(1, 120) as $attempt) {
        RateLimiter::hit(md5('demo-start'.'all'), 3600);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->post(route('demo.store'))->assertTooManyRequests();

    expect(DemoSandbox::count())->toBe(0);
});

it('holds one address to five an hour even when a burst gets past the limiter', function () {
    // Requests sent at once all pass the limiter's check before any adds to its count; without it, this is what each sees.
    $this->withoutMiddleware(ThrottleRequests::class);
    // Retry-After counts down from the first sandbox's start, so a second ticking over mid-test would read 3599.
    $this->freezeTime();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('demo.store'))->assertRedirect();
        app('auth')->forgetGuards();
    }

    $this->post(route('demo.store'))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '3600')
        ->assertSeeText('Too many tries. Try again in about an hour.');

    expect(DemoSandbox::count())->toBe(5);

    $this->travel(61)->minutes();

    $this->post(route('demo.store'))->assertRedirect();
});

it('holds everyone together to the hourly total even when a burst gets past the limiter', function () {
    config(['demo.starts_per_hour.all' => 3]);
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $address) {
        $this->withServerVariables(['REMOTE_ADDR' => $address])->post(route('demo.store'))->assertRedirect();
    }

    $this->travel(20)->minutes();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])->post(route('demo.store'))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', (string) (40 * 60))
        ->assertSeeText('Too many tries. Try again in 40 minutes.');

    expect(DemoSandbox::count())->toBe(3);
});

it('makes the slow password hash before the transaction, so it never holds the database\'s write lock', function () {
    // The hash manager, noting the transaction level each hash is made at.
    $hash = new class(app()) extends HashManager
    {
        /** @var list<int> */
        public array $levels = [];

        public function make($value, array $options = []): string
        {
            $this->levels[] = DB::transactionLevel();

            return parent::make($value, $options);
        }
    };

    Hash::swap($hash);

    $outside = DB::transactionLevel();

    Sandboxes::start();

    expect($hash->levels)->not->toBeEmpty()->each->toBe($outside);
});

it('does not exist unless the demo is hosted', function () {
    config(['demo.hosted' => false]);

    $this->post(route('demo.store'))->assertNotFound();

    expect(DemoSandbox::count())->toBe(0);
});
