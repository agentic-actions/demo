<?php

use AgenticActions\Facades\Actions;
use AgenticActions\Streaming\AgenticConversation;
use App\Ai\Agents\BoardAssistant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boards;

/*
 * 2026_09_26_112312 moves the board assistant's conversations from the demo's old assistant_conversations table into
 * the package's agentic_conversations. down() recreates the old table and moves the rows back, so each test starts
 * from the state an app was in before the migration ran.
 */

beforeEach(function () {
    $this->boards = Boards::make();
    $this->migration = require database_path('migrations/2026_09_26_112312_move_assistant_conversations_to_agentic_conversations.php');

    // One demo-mode turn through the real endpoint, read to the end so the conversation store saves it.
    $this->turn = fn (User $user, string $team): TestResponse => tap($this->actingAs($user)->json(
        'POST',
        route('assistant', ['current_team' => $team]),
        ['messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'How are we doing?']]]]],
        ['Accept' => 'application/json, text/event-stream'],
    ), fn (TestResponse $response) => $response->assertOk()->streamedContent());

    $this->conversationId = fn (User $user, Team $team): ?string => Actions::conversation(BoardAssistant::class, $user, $team)->id();
});

afterEach(function () {
    ignore_user_abort(false);
});

it('moves every row back to assistant_conversations and forward again, keeping the conversation ids', function () {
    ($this->turn)($this->boards->member, 'acme');
    ($this->turn)($this->boards->member, 'globex');
    ($this->turn)($this->boards->owner, 'acme');

    $before = AgenticConversation::query()->orderBy('conversation_id')->get(['participant_id', 'tenant_id', 'conversation_id'])->toArray();

    $this->migration->down();

    expect(AgenticConversation::query()->count())->toBe(0)
        ->and(DB::table('assistant_conversations')->orderBy('conversation_id')->get(['user_id', 'team_id', 'conversation_id'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe(array_map(fn (array $row): array => ['user_id' => $row['participant_id'], 'team_id' => $row['tenant_id'], 'conversation_id' => $row['conversation_id']], $before));

    $this->migration->up();

    expect(Schema::hasTable('assistant_conversations'))->toBeFalse()
        ->and(AgenticConversation::query()->orderBy('conversation_id')->get(['participant_id', 'tenant_id', 'conversation_id'])->toArray())->toBe($before)
        ->and(AgenticConversation::query()->distinct()->pluck('agent')->all())->toBe([BoardAssistant::class]);
});

it('opens a moved conversation for the same user and team, and a fresh one in another team', function () {
    ($this->turn)($this->boards->member, 'acme');

    $acme = ($this->conversationId)($this->boards->member, $this->boards->acme);

    $this->migration->down();
    $this->migration->up();

    expect(($this->conversationId)($this->boards->member, $this->boards->acme))->toBe($acme)
        ->and(($this->conversationId)($this->boards->member, $this->boards->globex))->toBeNull();

    $this->actingAs($this->boards->member)
        ->getJson(route('assistant.transcript', ['current_team' => 'acme']))
        ->assertOk()
        ->assertJsonPath('messages.0.parts.0.text', 'How are we doing?')
        ->assertJsonCount(2, 'messages');

    $this->actingAs($this->boards->member)
        ->getJson(route('assistant.transcript', ['current_team' => 'globex']))
        ->assertExactJson(['messages' => [], 'demo' => true, 'max_length' => 4000]);

    ($this->turn)($this->boards->member, 'globex');

    expect(($this->conversationId)($this->boards->member, $this->boards->globex))->not->toBeNull()->not->toBe($acme)
        ->and(($this->conversationId)($this->boards->member, $this->boards->acme))->toBe($acme);
});
