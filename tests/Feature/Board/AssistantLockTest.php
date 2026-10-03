<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boards;
use Tests\Support\StreamParts;

/*
 * One reply at a time per person and team. A streamed turn runs while its response is sent, so a response not yet
 * read stands for a turn still running: a second turn sent meanwhile answers 429 with one sentence, and runs nothing.
 */

beforeEach(function () {
    $this->boards = Boards::make();

    $this->send = fn (User $user, string $words, ?Team $team = null): TestResponse => $this->actingAs($user)->postJson(
        route('assistant', ['current_team' => ($team ?? $this->boards->acme)->slug]),
        [
            'id' => 'assistant',
            'messages' => [['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $words]]]],
            'trigger' => 'submit-message',
        ],
        ['Accept' => 'application/json, text/event-stream'],
    );
});

afterEach(function () {
    ignore_user_abort(false);
});

it('answers a second turn with 429 while the first is still streaming, and lets the next one through', function () {
    $first = ($this->send)($this->boards->owner, 'Add a task called "Book the venue"');

    ($this->send)($this->boards->owner, 'Add a task called "Hire a band"')
        ->assertTooManyRequests()
        ->assertExactJson(['message' => 'The assistant is still answering. Wait for that reply, then send again.']);

    expect(StreamParts::text(StreamParts::of($first->assertOk()->streamedContent())))->toStartWith('I added “Book the venue”');

    ($this->send)($this->boards->owner, 'Add a task called "Hire a band"')->assertOk()->streamedContent();

    expect($this->boards->acme->tasks()->whereIn('title', ['Book the venue', 'Hire a band'])->count())->toBe(2);
});

it('holds one turn per person and team, not one for everybody', function () {
    $marcusInAcme = ($this->send)($this->boards->member, 'How are we doing?');

    ($this->send)($this->boards->member, 'How are we doing?', $this->boards->globex)->assertOk()->streamedContent();
    ($this->send)($this->boards->owner, 'How are we doing?')->assertOk()->streamedContent();
    ($this->send)($this->boards->member, 'How are we doing?')->assertTooManyRequests();

    $marcusInAcme->assertOk()->streamedContent();
});

it('lets the lock go when a request is answered without a turn', function () {
    ($this->send)($this->boards->owner, '   ')->assertUnprocessable();

    ($this->send)($this->boards->owner, 'How are we doing?')->assertOk()->streamedContent();
});
