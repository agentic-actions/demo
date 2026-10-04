<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boards;
use Tests\Support\OAuthClient;
use Tests\Support\Sandboxes;

/*
 * The named limiters (AppServiceProvider::configureRateLimits). Each surface has an allowance of its own, keyed by
 * person or address, so using one up never spends another: the assistant, its transcript, the board's change feed and
 * its other calls, team settings, tokens, "Try the demo" and its resume link, and the OAuth endpoints. A 429 says what
 * to do in one plain line.
 */

beforeEach(function () {
    $this->boards = Boards::make();

    // One assistant turn, read to its end so its reply lock is let go.
    $this->turn = fn (User $user): TestResponse => $this->actingAs($user)->postJson(
        route('assistant', ['current_team' => 'acme']),
        [
            'id' => 'assistant',
            'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'How are we doing?']]]],
            'trigger' => 'submit-message',
        ],
        ['Accept' => 'application/json, text/event-stream'],
    );

    $this->tooMany = 'Too many tries. Wait a minute and try again.';

    $this->poll = fn (): TestResponse => $this->postJson(Boards::url('_changes', $this->boards->acme), ['since' => null]);
});

afterEach(function () {
    ignore_user_abort(false);
});

describe('the assistant', function () {
    it('takes ten turns a minute from one person, then answers 429, while the transcript and a new token still go through', function () {
        $member = $this->boards->member;

        foreach (range(1, 10) as $attempt) {
            ($this->turn)($member)->assertOk()->streamedContent();
        }

        ($this->turn)($member)->assertTooManyRequests()->assertExactJson(['message' => $this->tooMany]);

        $this->get(route('assistant.transcript', ['current_team' => 'acme']))->assertOk();
        $this->post(route('teams.tokens.store', ['team' => 'acme']), ['name' => 'Cursor', 'access' => 'read'])
            ->assertRedirect(route('teams.tokens.index', ['team' => 'acme']));

        $this->travel(61)->seconds();

        ($this->turn)($member)->assertOk()->streamedContent();
    });

    it('takes 300 turns a day from one person on the hosted demo, which bounds the conversation a sandbox keeps', function () {
        config(['demo.hosted' => true]);

        $member = $this->boards->member;

        foreach (range(1, 299) as $attempt) {
            RateLimiter::hit(md5('assistant'.'user:'.$member->id.'|day'), 86400);
        }

        ($this->turn)($member)->assertOk()->streamedContent();

        $this->travel(61)->seconds();

        ($this->turn)($member)->assertTooManyRequests()->assertJsonPath('message', 'Too many tries. Try again in about 24 hours.');

        ($this->turn)($this->boards->owner)->assertOk()->streamedContent();
    });

    it('has no daily allowance on a local copy', function () {
        foreach (range(1, 300) as $attempt) {
            RateLimiter::hit(md5('assistant'.'user:'.$this->boards->member->id.'|day'), 86400);
        }

        ($this->turn)($this->boards->member)->assertOk()->streamedContent();
    });

    it('takes 30 turns a minute from one address, whoever sends them', function () {
        foreach ([$this->boards->owner, $this->boards->admin, $this->boards->member] as $person) {
            foreach (range(1, 10) as $attempt) {
                ($this->turn)($person)->assertOk()->streamedContent();
            }
        }

        ($this->turn)($this->boards->viewer)->assertTooManyRequests();
    });

    it('allows 30 transcript reads a minute', function () {
        $this->actingAs($this->boards->member);

        foreach (range(1, 30) as $attempt) {
            $this->get(route('assistant.transcript', ['current_team' => 'acme']))->assertOk();
        }

        $this->get(route('assistant.transcript', ['current_team' => 'acme']))->assertTooManyRequests();

        ($this->turn)($this->boards->member)->assertOk()->streamedContent();
    });
});

describe('the board', function () {
    it('keeps two open tabs, each polling every five seconds, under the change feed\'s limit', function () {
        $this->actingAs($this->boards->member);

        // Three minutes of two visible tabs.
        foreach (range(1, 36) as $tick) {
            ($this->poll)()->assertOk();
            ($this->poll)()->assertOk();

            $this->travel(5)->seconds();
        }
    });

    it('answers the 31st poll in a minute with 429, while a new task still goes through', function () {
        $this->actingAs($this->boards->member);

        foreach (range(1, 30) as $attempt) {
            ($this->poll)()->assertOk();
        }

        ($this->poll)()->assertTooManyRequests()->assertExactJson(['message' => $this->tooMany]);

        $this->postJson(Boards::url('create-task', $this->boards->acme), ['title' => 'Written while the feed waits'])->assertOk();

        expect($this->boards->acme->tasks()->where('title', 'Written while the feed waits')->exists())->toBeTrue();
    });

    it('answers the 61st call in a minute with 429, while the change feed still answers', function () {
        $this->actingAs($this->boards->member);

        foreach (range(1, 60) as $attempt) {
            $this->postJson(Boards::url('list-tasks', $this->boards->acme))->assertOk();
        }

        $this->postJson(Boards::url('list-tasks', $this->boards->acme))->assertTooManyRequests();

        ($this->poll)()->assertOk();
    });

    it('counts each person on their own', function () {
        $this->actingAs($this->boards->member);

        foreach (range(1, 30) as $attempt) {
            ($this->poll)()->assertOk();
        }

        ($this->poll)()->assertTooManyRequests();

        $this->actingAs($this->boards->owner);

        ($this->poll)()->assertOk();
    });
});

describe('settings', function () {
    it('allows 20 team changes a minute, and the board still answers after', function () {
        $this->actingAs($this->boards->member);

        foreach (range(1, 20) as $attempt) {
            $this->post(route('teams.switch', ['team' => 'acme']))->assertRedirect();
        }

        $this->post(route('teams.switch', ['team' => 'acme']))->assertTooManyRequests()->assertSeeText($this->tooMany);

        ($this->poll)()->assertOk();
    });

    it('puts every route that changes a profile, a team, a member, a token or a connection under that limit', function (string $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('throttle:settings');
    })->with([
        'profile.update', 'teams.store', 'teams.update', 'teams.destroy', 'teams.switch', 'teams.leave',
        'teams.members.update', 'teams.members.destroy', 'teams.tokens.destroy', 'teams.connections.destroy',
    ]);

    it('allows five new tokens a minute, and revoking one still works', function () {
        $this->actingAs($this->boards->member);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('teams.tokens.store', ['team' => 'acme']), ['name' => "Client {$attempt}", 'access' => 'read'])->assertRedirect();
        }

        $this->post(route('teams.tokens.store', ['team' => 'acme']), ['name' => 'One more', 'access' => 'read'])
            ->assertTooManyRequests()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Retry-After')
            ->assertSeeText($this->tooMany);

        $token = $this->boards->member->tokens()->first();

        $this->delete(route('teams.tokens.destroy', ['team' => 'acme', 'token' => $token->id]))->assertRedirect();

        expect($this->boards->member->tokens()->count())->toBe(4);
    });
});

describe('the hosted demo', function () {
    beforeEach(function () {
        config(['demo.hosted' => true]);
    });

    it('stops a sixth sandbox from one address, and the same address can still use a resume link', function () {
        // Retry-After counts down from the first sandbox's start, so a second ticking over mid-test would read 3599.
        $this->freezeTime();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('demo.store'))->assertRedirect();
        }

        $this->post(route('demo.store'))
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '3600')
            ->assertSeeText('Too many tries. Try again in about an hour.');

        $sandbox = Sandboxes::start();

        $this->get($sandbox->resumeUrl())->assertRedirect();

        $this->assertAuthenticatedAs($sandbox->visitor);
    });

    it('allows 30 resume links a minute from one address', function () {
        $sandbox = Sandboxes::start();

        foreach (range(1, 30) as $attempt) {
            $this->get($sandbox->resumeUrl())->assertRedirect();
        }

        $this->get($sandbox->resumeUrl())->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.12'])->get($sandbox->resumeUrl())->assertRedirect();
    });

    it('allows 30 "View as" switches a minute from one address', function () {
        $sandbox = Sandboxes::start();
        $marcus = Sandboxes::person($sandbox, 'marcus');

        $this->actingAs(Sandboxes::person($sandbox, 'you'));

        foreach (range(1, 30) as $attempt) {
            $this->post(route('demo.as', ['persona' => $marcus->id]))->assertRedirect();
        }

        $this->post(route('demo.as', ['persona' => $marcus->id]))->assertTooManyRequests();
    });
});

describe('OAuth', function () {
    it('allows 120 discovery reads a minute from one address, and registration keeps its own allowance', function () {
        foreach (range(1, 120) as $attempt) {
            $this->getJson('/.well-known/oauth-protected-resource')->assertOk();
        }

        $this->getJson('/.well-known/oauth-authorization-server')->assertTooManyRequests();

        expect((new OAuthClient($this))->id)->not->toBe('');
    });

    it('allows 20 consent screens a minute per person, and leaves the token endpoint to Passport', function () {
        $claude = new OAuthClient($this);

        foreach (range(1, 20) as $attempt) {
            $claude->authorize($this->boards->member, $this->boards->acme)->assertOk();
        }

        $claude->authorize($this->boards->member, $this->boards->acme)->assertTooManyRequests();
        $claude->authorize($this->boards->owner, $this->boards->acme)->assertOk();

        // A code for the owner goes on to a token: the consent limit does not reach oauth/token.
        $approval = $claude->answer($claude->authorize($this->boards->owner, $this->boards->acme)->inertiaProps('approve'));

        $claude->token($approval, $this->boards->acme)->assertOk();
    });
});
