<?php

use App\Ai\ScriptedPlanner;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\Environment;
use Tests\Support\Sandboxes;
use Tests\Support\StreamParts;

/*
 * Scripted mode on the hosted demo: DEMO_HOSTED forces the assistant onto the scripted planner whatever key the
 * environment holds, the key reads null, and the app refuses every outbound HTTP request, so no turn can reach a
 * model. The planner's replies on the host point to MCP instead of .env, and the titles and names it repeats are
 * Markdown-escaped.
 */

beforeEach(function () {
    config(['demo.hosted' => true]);

    $this->sandbox = Sandboxes::start();
    $this->you = Sandboxes::person($this->sandbox, 'you');
    $this->acme = Sandboxes::team($this->sandbox, 'Acme');

    // One turn as the panel posts it, and its parts.
    $this->send = fn (User $user, string $words): TestResponse => $this->actingAs($user)->postJson(
        route('assistant', ['current_team' => $this->acme->slug]),
        [
            'id' => 'assistant',
            'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $words]]]],
            'trigger' => 'submit-message',
            'page' => ['url' => "/{$this->acme->slug}/board", 'component' => 'board'],
        ],
        ['Accept' => 'application/json, text/event-stream'],
    );

    $this->turn = fn (User $user, string $words): array => StreamParts::of(($this->send)($user, $words)->assertOk()->streamedContent());
});

/**
 * config/ai.php as it reads under these environment variables, with the process environment put back after.
 *
 * @param  array<string, string>  $variables
 * @return array<string, mixed>
 */
function aiConfigUnder(array $variables): array
{
    return Environment::with($variables, fn (): array => require config_path('ai.php'));
}

describe('the switch', function () {
    it('runs scripted on the hosted demo with a key in the environment, and the key reads null', function () {
        config(['ai' => aiConfigUnder(['DEMO_HOSTED' => 'true', 'OPENROUTER_API_KEY' => 'test-openrouter-key'])]);

        expect(config('ai.scripted'))->toBeTrue()
            ->and(config('ai.providers.openrouter.key'))->toBeNull()
            ->and(ScriptedPlanner::active())->toBeTrue();
    });

    it('stays scripted on the hosted demo when ASSISTANT_SCRIPTED says false', function () {
        config(['ai' => aiConfigUnder(['DEMO_HOSTED' => 'true', 'ASSISTANT_SCRIPTED' => 'false', 'OPENROUTER_API_KEY' => 'test-openrouter-key'])]);

        expect(config('ai.providers.openrouter.key'))->toBeNull()
            ->and(ScriptedPlanner::active())->toBeTrue();
    });

    it('runs scripted on a copy with ASSISTANT_SCRIPTED, the key unread', function () {
        config(['ai' => aiConfigUnder(['DEMO_HOSTED' => 'false', 'ASSISTANT_SCRIPTED' => 'true', 'OPENROUTER_API_KEY' => 'test-openrouter-key'])]);

        expect(config('ai.providers.openrouter.key'))->toBeNull()
            ->and(ScriptedPlanner::active())->toBeTrue();
    });

    it('keeps a real model on a copy with a key and neither flag', function () {
        config(['demo.hosted' => false]);
        config(['ai' => aiConfigUnder(['DEMO_HOSTED' => 'false', 'ASSISTANT_SCRIPTED' => 'false', 'OPENROUTER_API_KEY' => 'test-openrouter-key'])]);

        expect(config('ai.scripted'))->toBeFalse()
            ->and(config('ai.providers.openrouter.key'))->toBe('test-openrouter-key')
            ->and(ScriptedPlanner::active())->toBeFalse();

        $this->actingAs($this->you)
            ->getJson(route('assistant.transcript', ['current_team' => $this->acme->slug]))
            ->assertOk()
            ->assertJsonPath('demo', false);
    });

    it('leaves the environment as it found it', function () {
        aiConfigUnder(['DEMO_HOSTED' => 'true', 'OPENROUTER_API_KEY' => 'test-openrouter-key']);

        expect(env('OPENROUTER_API_KEY'))->toBeEmpty()
            ->and(env('DEMO_HOSTED'))->toBeEmpty();
    });
});

describe('outbound requests', function () {
    it('are refused once the hosted demo boots', function () {
        expect(Http::preventingStrayRequests())->toBeFalse();

        (new AppServiceProvider(app()))->boot();

        expect(Http::preventingStrayRequests())->toBeTrue()
            ->and(fn () => Http::post('https://openrouter.ai/api/v1/chat/completions'))->toThrow(StrayRequestException::class);
    });

    it('are left alone on a copy', function () {
        config(['demo.hosted' => false]);

        (new AppServiceProvider(app()))->boot();

        expect(Http::preventingStrayRequests())->toBeFalse();
    });

    it('never happen in a whole scripted turn, even with a key in the config', function () {
        config(['ai.scripted' => true, 'ai.providers.openrouter.key' => 'test-openrouter-key']);
        Http::preventStrayRequests();

        $before = $this->acme->tasks()->count();
        $parts = ($this->turn)($this->you, 'Add three launch tasks for Marcus');

        expect(collect(StreamParts::rows($parts))->where('status', 'done')->pluck('action')->all())->toBe(['create-task', 'create-task', 'create-task'])
            ->and(StreamParts::text($parts))->toStartWith('I added 3 tasks for Marcus Reed:')
            ->and($this->acme->tasks()->count())->toBe($before + 3);
    });
});

describe('the replies', function () {
    it('say on the hosted demo that replies are scripted and MCP reaches a real model', function () {
        $text = StreamParts::text(($this->turn)($this->you, 'Tell me a joke'));

        expect($text)->toStartWith('This is a scripted demo. My replies follow a script, and the actions, permissions and cards are real.')
            ->toEndWith('To use a real model on this board, connect Claude over MCP from the AI clients page.')
            ->not->toContain('.env');
    });

    it('point to .env on a copy', function () {
        config(['demo.hosted' => false]);

        expect(StreamParts::text(($this->turn)($this->you, 'Tell me a joke')))->toEndWith('Add OPENROUTER_API_KEY to .env to talk to a real model.');
    });

    it('escape the Markdown in titles and names they repeat', function () {
        $this->you->forceFill(['name' => 'Pat_the *Owner*'])->save();

        Task::factory()->create([
            'team_id' => $this->acme->id,
            'assignee_id' => $this->you->id,
            'title' => 'Fix [the link](https://example.test) <b>now</b>',
            'status' => TaskStatus::Todo,
            'due_on' => today()->subDay(),
        ]);

        expect(StreamParts::text(($this->turn)($this->you, "What's overdue?")))
            ->toContain('- Fix \[the link\]\(https://example.test\) \<b\>now\</b\> (Pat\_the \*Owner\*, due ');

        expect(StreamParts::text(($this->turn)($this->you, "Move '**Ship** it' to done")))
            ->toBe('I couldn\'t move “\*\*Ship\*\* it”: No open task has that title.');
    });
});
