<?php

use App\Models\DemoSandbox;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Boards;
use Tests\Support\Sandboxes;

/*
 * The starter kit on the hosted demo: no sign-up, password reset, email verification, two-factor or passkeys (404);
 * no email change, account deletion, password change or invitation (403, with a line saying why); teams a visitor adds
 * join their sandbox, up to a cap; nothing is mailed; and the pages get the sandbox as the shared "demo" prop.
 */

beforeEach(function () {
    config(['demo.hosted' => true]);

    $this->sandbox = Sandboxes::start();
    $this->you = Sandboxes::person($this->sandbox, 'you');
    $this->acme = Sandboxes::team($this->sandbox, 'Acme');
});

describe('routes that are absent', function () {
    it('answers 404 to a guest', function (string $method, string $uri) {
        $this->json($method, $uri)->assertNotFound();
    })->with([
        'sign-up page' => ['GET', '/register'],
        'sign-up' => ['POST', '/register'],
        'reset request page' => ['GET', '/forgot-password'],
        'reset request' => ['POST', '/forgot-password'],
        'reset page' => ['GET', '/reset-password/some-token'],
        'reset' => ['POST', '/reset-password'],
        'two-factor challenge page' => ['GET', '/two-factor-challenge'],
        'two-factor challenge' => ['POST', '/two-factor-challenge'],
        'passkey sign-in options' => ['GET', '/passkeys/login/options'],
        'passkey sign-in' => ['POST', '/passkeys/login'],
        'passkey endpoints' => ['GET', '/.well-known/passkey-endpoints'],
    ]);

    it('answers 404 to a signed-in visitor', function (string $method, string $uri) {
        $this->actingAs($this->you)->json($method, $uri)->assertNotFound();
    })->with([
        'verification notice' => ['GET', '/email/verify'],
        'verification link' => ['GET', '/email/verify/1/hash'],
        'verification resend' => ['POST', '/email/verification-notification'],
        'two-factor enable' => ['POST', '/user/two-factor-authentication'],
        'two-factor confirm' => ['POST', '/user/confirmed-two-factor-authentication'],
        'two-factor disable' => ['DELETE', '/user/two-factor-authentication'],
        'two-factor QR code' => ['GET', '/user/two-factor-qr-code'],
        'two-factor recovery codes' => ['GET', '/user/two-factor-recovery-codes'],
        'passkey options' => ['GET', '/user/passkeys/options'],
        'passkey add' => ['POST', '/user/passkeys'],
        'passkey confirm' => ['GET', '/passkeys/confirm/options'],
        'password confirmation page' => ['GET', '/user/confirm-password'],
        'password confirmation' => ['POST', '/user/confirm-password'],
        'password confirmation status' => ['GET', '/user/confirmed-password-status'],
    ]);

    it('sends the security page, which holds only those, to the profile page', function () {
        $this->actingAs($this->you)->get(route('security.edit'))->assertRedirect(route('profile.edit'));

        config(['demo.hosted' => false]);

        $this->actingAs($this->you)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    });

    it('keeps them when the demo is not hosted', function () {
        config(['demo.hosted' => false]);

        $this->get('/register')->assertOk();
        $this->get('/forgot-password')->assertOk();
        $this->get('/.well-known/passkey-endpoints')->assertOk();
    });

    it('keeps the sign-in page, without a reset link', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login')->where('canResetPassword', false));
    });
});

describe('changes that are refused', function () {
    it('refuses an email change with a line saying why', function () {
        $email = $this->you->email;

        $this->actingAs($this->you)
            ->patch(route('profile.update'), ['name' => 'You', 'email' => 'me@example.com'])
            ->assertForbidden()
            ->assertSeeText('Email changes are off in this demo.')
            ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'Email changes are off in this demo.']);

        expect($this->you->refresh()->email)->toBe($email)
            ->and($this->you->hasVerifiedEmail())->toBeTrue();
    });

    it('leaves the line to the page on an Inertia visit, so the toast shows once', function () {
        $this->actingAs($this->you)
            ->withHeaders(['X-Inertia' => 'true'])
            ->patch(route('profile.update'), ['name' => 'You', 'email' => 'me@example.com'])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('Email changes are off in this demo.')
            ->assertSessionMissing('inertia.flash_data');
    });

    it('refuses an email that differs only in case', function () {
        $this->actingAs($this->you)
            ->patchJson(route('profile.update'), ['name' => 'You', 'email' => strtoupper($this->you->email)])
            ->assertForbidden()
            ->assertJsonPath('message', 'Email changes are off in this demo.');

        expect($this->you->refresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('allows a name change', function () {
        $this->actingAs($this->you)
            ->patch(route('profile.update'), ['name' => 'Sam', 'email' => $this->you->email])
            ->assertRedirect(route('profile.edit'));

        expect($this->you->refresh()->name)->toBe('Sam')
            ->and($this->you->hasVerifiedEmail())->toBeTrue();
    });

    it('refuses with 403', function (string $method, Closure $uri, array $data, string $line) {
        $this->actingAs($this->you)
            ->json($method, $uri($this->acme), $data)
            ->assertForbidden()
            ->assertJsonPath('message', $line);
    })->with([
        'account deletion' => ['DELETE', fn (): string => route('profile.destroy'), ['password' => 'password'], 'This demo account is deleted on its own when the demo ends.'],
        'password change' => ['PUT', fn (): string => route('user-password.update'), ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'], 'Passwords are off in this demo. Use your resume link to sign in again.'],
        'invitation' => ['POST', fn (Team $acme): string => route('teams.invitations.store', ['team' => $acme->slug]), ['email' => 'friend@example.com', 'role' => 'member'], 'Invitations are off in this demo, because they send email.'],
    ]);

    it('leaves the account and the team as they were', function () {
        Notification::fake();

        $this->actingAs($this->you)->delete(route('profile.destroy'), ['password' => 'password'])->assertForbidden();
        $this->actingAs($this->you)->post(route('teams.invitations.store', ['team' => $this->acme->slug]), ['email' => 'friend@example.com', 'role' => 'member'])->assertForbidden();

        $this->assertModelExists($this->you);
        $this->assertDatabaseCount('team_invitations', 0);
        Notification::assertNothingSent();
    });

    it('allows them when the demo is not hosted', function () {
        config(['demo.hosted' => false]);
        Notification::fake();

        $this->actingAs($this->you)
            ->post(route('teams.invitations.store', ['team' => $this->acme->slug]), ['email' => 'friend@example.com', 'role' => 'member'])
            ->assertRedirect();

        $this->assertDatabaseCount('team_invitations', 1);
    });
});

describe('teams a visitor adds', function () {
    it('joins them to the sandbox', function () {
        $this->actingAs($this->you)->post(route('teams.store'), ['name' => 'Initech'])->assertRedirect();

        expect(Team::where('name', 'Initech')->sole()->demo_sandbox_id)->toBe($this->sandbox->id);
    });

    it('holds five on top of Acme and Globex, deleted ones included', function () {
        foreach (range(1, 4) as $number) {
            $this->actingAs($this->you)->post(route('teams.store'), ['name' => "Team {$number}"])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->you)->post(route('teams.store'), ['name' => 'Team 5'])->assertSessionHasNoErrors();
        Team::where('name', 'Team 5')->sole()->delete();

        $this->actingAs($this->you)
            ->post(route('teams.store'), ['name' => 'Team 6'])
            ->assertSessionHasErrors(['name' => 'This demo already has as many teams as it can hold.']);

        $this->assertDatabaseMissing('teams', ['name' => 'Team 6']);
    });

    it('gives them a random slug, created or renamed, so no visitor learns another\'s team names', function () {
        $other = Sandboxes::person(Sandboxes::start(), 'you');

        $this->actingAs($this->you)->post(route('teams.store'), ['name' => 'Merger with Initech'])->assertSessionHasNoErrors();
        $this->actingAs($other)->post(route('teams.store'), ['name' => 'Merger with Initech'])->assertSessionHasNoErrors();

        $slugs = Team::where('name', 'Merger with Initech')->orderBy('id')->pluck('slug')->all();

        expect($slugs[0])->toMatch('/^merger-with-initech-[a-z0-9]{6}$/')
            ->and($slugs[1])->toMatch('/^merger-with-initech-[a-z0-9]{6}$/')
            ->and($slugs[0])->not->toBe($slugs[1]);

        $this->actingAs($this->you)->patch(route('teams.update', ['team' => $this->acme->slug]), ['name' => 'Orbit Labs'])->assertSessionHasNoErrors();

        expect($this->acme->fresh()->slug)->toMatch('/^orbit-labs-[a-z0-9]{6}$/');

        // A team outside any sandbox keeps the kit's numbered slugs.
        $this->actingAs(User::factory()->create())->post(route('teams.store'), ['name' => 'Merger with Initech'])->assertSessionHasNoErrors();

        expect(Team::where('name', 'Merger with Initech')->whereNull('demo_sandbox_id')->sole()->slug)->toMatch('/^merger-with-initech(-\d+)?$/');
    });

    it('counts per sandbox', function () {
        config(['demo.caps.teams_per_sandbox' => 0]);

        $this->actingAs($this->you)->post(route('teams.store'), ['name' => 'Initech'])->assertSessionHasErrors('name');

        config(['demo.hosted' => false]);

        $this->actingAs($this->you)->post(route('teams.store'), ['name' => 'Initech'])->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create())->post(route('teams.store'), ['name' => 'Hooli'])->assertSessionHasNoErrors();

        expect(Team::where('name', 'Hooli')->sole()->demo_sandbox_id)->toBeNull();
    });
});

it('sends no mail during a whole sandbox session', function () {
    Mail::fake();
    Notification::fake();

    $this->post(route('demo.store'))->assertRedirect();

    $sandbox = DemoSandbox::latest('id')->first();
    $acme = Sandboxes::team($sandbox, 'Acme');
    $marcus = Sandboxes::person($sandbox, 'marcus');

    $this->get(route('board', ['current_team' => $acme->slug]))->assertOk();
    $this->postJson(Boards::url('create-task', $acme), ['title' => 'Plan the offsite', 'assignee' => 'Marcus Reed'])->assertOk();
    $this->patch(route('profile.update'), ['name' => 'Sam', 'email' => Sandboxes::person($sandbox, 'you')->email])->assertRedirect();
    $this->patch(route('profile.update'), ['name' => 'Sam', 'email' => 'sam@example.com'])->assertForbidden();
    $this->post('/email/verification-notification')->assertNotFound();
    $this->post(route('teams.invitations.store', ['team' => $acme->slug]), ['email' => 'friend@example.com', 'role' => 'member'])->assertForbidden();
    $this->post(route('teams.store'), ['name' => 'Initech'])->assertRedirect();
    $this->post(route('demo.as', ['persona' => $marcus->id]))->assertRedirect();
    $this->get($sandbox->resumeUrl())->assertRedirect();
    $this->post(route('logout'))->assertRedirect();
    $this->post('/forgot-password', ['email' => $marcus->email])->assertNotFound();
    $this->post('/register', ['name' => 'Mallory', 'email' => 'mallory@example.com', 'password' => 'password', 'password_confirmation' => 'password'])->assertNotFound();

    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

describe('the shared demo prop', function () {
    it('gives the sandbox, its resume link and its people, the visitor first and current', function () {
        $this->freezeSecond();

        // Another visitor's sandbox, and someone outside any sandbox: neither's people may be listed.
        Sandboxes::start();
        User::factory()->create();

        $ids = User::where('demo_sandbox_id', $this->sandbox->id)->orderBy('id')->pluck('id')->all();

        $this->actingAs($this->you)
            ->get(route('board', ['current_team' => $this->acme->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('demo.hosted', true)
                ->where('demo.expires_at', $this->sandbox->expires_at->toIso8601String())
                ->where('demo.resume_url', $this->sandbox->resumeUrl())
                ->has('demo.personas', 4)
                ->where('demo.personas', fn ($personas): bool => collect($personas)->pluck('id')->sort()->values()->all() === $ids)
                ->where('demo.personas.0', ['id' => $this->you->id, 'name' => 'You', 'roles' => [['team' => 'Acme', 'role' => 'Owner']], 'current' => true])
                ->where('demo.personas.1.name', 'Marcus Reed')
                ->where('demo.personas.1.roles', [['team' => 'Acme', 'role' => 'Member'], ['team' => 'Globex', 'role' => 'Member']])
                ->where('demo.personas.1.current', false)
                ->where('demo.personas.2.roles', [['team' => 'Acme', 'role' => 'Viewer']])
                ->where('demo.personas.3.roles', [['team' => 'Globex', 'role' => 'Owner']]));
    });

    it('marks the persona being viewed as current', function () {
        $vera = Sandboxes::person($this->sandbox, 'vera');

        $this->actingAs($vera)
            ->get(route('board', ['current_team' => $this->acme->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('demo.personas.0.current', false)
                ->where('demo.personas.2.id', $vera->id)
                ->where('demo.personas.2.current', true));
    });

    it('says only that the demo is hosted to a guest', function () {
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('demo', ['hosted' => true, 'expires_at' => null, 'resume_url' => null, 'personas' => []]));
    });

    it('gives the front page the hosted demo', function () {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('welcome')->where('demo.hosted', true));
    });

    it('says the demo is not hosted on a local copy', function () {
        config(['demo.hosted' => false]);

        $this->actingAs($this->you)
            ->get(route('board', ['current_team' => $this->acme->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('demo', ['hosted' => false, 'expires_at' => null, 'resume_url' => null, 'personas' => []]));

        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->component('welcome')->where('demo.hosted', false));
    });
});
