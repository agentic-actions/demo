<?php

namespace App\Ai;

use App\Ai\Agents\BoardAssistant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Generator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Demo mode. With no OPENROUTER_API_KEY, or in scripted mode (ASSISTANT_SCRIPTED, and always on the hosted demo), the
 * board assistant runs on laravel/ai's own fake gateway and this planner plays the model: it turns a few requests into
 * tool calls, reads each tool's answer the way a model would (the sentence the package wrote, and the data after
 * "---"), and writes the reply from what it read. Titles and names it repeats are Markdown-escaped, since the panel
 * renders the reply as Markdown and people choose them.
 *
 * The gateway is installed on the provider, where a real model's gateway sits, not with BoardAssistant::fake(): a
 * delete the person confirms, and a task whose missing details the person fills in a form, then resume exactly as they
 * do with a real model. Everything else is the real path too: the same endpoint, ActionsProtocol, the actions with
 * their authorization and team scope, the confirmation card, the form, and the conversation store. A call to a tool
 * this person was not given is answered by laravel/ai (#[RepairToolCalls]) with "does not exist", and the planner says
 * so, as a model would.
 */
final class ScriptedPlanner
{
    /**
     * Titles for "add three launch tasks".
     */
    private const LAUNCH_TASKS = [
        'Draft the launch checklist',
        'Brief the support team on the launch',
        'Schedule the launch social posts',
        'Book the launch retrospective',
        'Prepare the launch press kit',
    ];

    /**
     * The script for this turn: it yields tool calls, receives what each tool answered, and returns the reply.
     *
     * @var Generator<int, ToolCall, string, string>
     */
    private Generator $script;

    /**
     * The call the script is waiting on.
     */
    private ?ToolCall $pending = null;

    /**
     * The tool results of this turn so far, as the model is sent them.
     *
     * @var list<ToolResult>
     */
    private array $results = [];

    /**
     * The task the person asked to delete, for the reply when delete-task turns out to be missing or refused.
     */
    private ?string $subject = null;

    /**
     * Create the planner for one turn of one assistant.
     */
    public function __construct(private readonly BoardAssistant $assistant) {}

    /**
     * Whether the assistant runs in demo mode: scripted mode is on (always on the hosted demo), or no OpenRouter key is
     * configured.
     */
    public static function active(): bool
    {
        return (bool) config('ai.scripted') || blank(config('ai.providers.openrouter.key'));
    }

    /**
     * Play the model for this turn with the script for these words. No words means the person answered a confirmation
     * card, and the turn resumes the call they answered.
     */
    public function drive(string $words): void
    {
        $this->script = $this->plan($words);

        Event::listen(StartingStep::class, function (StartingStep $event): void {
            if ($event->agent === $this->assistant) {
                $this->results = self::resultsOfThisTurn($event->messages);
                $this->pause();
            }
        });

        // A model takes a moment over each tool call; so does the demo, so the rows tick at a pace you can read.
        Event::listen(InvokingTool::class, function (InvokingTool $event): void {
            if ($event->agent === $this->assistant) {
                $this->pause(2);
            }
        });

        Ai::textProvider()->useTextGateway(new FakeTextGateway(fn (): ToolCall|string => $this->next()));
    }

    /**
     * The next step: the script's next tool call, or its reply.
     */
    private function next(): ToolCall|string
    {
        if ($this->pending !== null) {
            $result = collect($this->results)->first(fn (ToolResult $result): bool => $result->id === $this->pending->id);

            // laravel/ai answered a call to a tool this person was not given: "Tool '…' does not exist."
            if ($result === null || $result->failed) {
                return $this->unavailable($this->pending->name);
            }

            $this->script->send($result->text());
        }

        $this->pending = $this->script->valid() ? $this->script->current() : null;

        return $this->pending ?? $this->script->getReturn();
    }

    /**
     * Pick the script for the person's words.
     *
     * @return Generator<int, ToolCall, string, string>
     */
    private function plan(string $words): Generator
    {
        $text = Str::of($words)->lower()->squish()->toString();
        $quoted = self::quoted($words);

        if ($text === '') {
            return $this->answered();
        }

        if (preg_match('/\b(delete|remove)\s+(.+)$/', $text, $match) === 1) {
            return $this->deleteTask($quoted ?? trim(preg_replace('/^the\s+|\s+task$|[.!?]+$/', '', $match[2]) ?? ''));
        }

        $title = $quoted ?? self::titleBeforeDone($words);

        if ($title !== null && preg_match('/\b(done|complete|finish(ed)?)\b/', $text) === 1) {
            return $this->completeTask($title);
        }

        if (preg_match('/\b(?:add|create)\s+(?:a|an|one)\s+task\s+(?:for\s+(\S+)\s+)?to\s+(.+?)[.!?]*$/iu', trim($words), $match) === 1) {
            return $this->addTask(Str::ucfirst(trim($match[2])), $match[1] === '' ? null : $match[1]);
        }

        if (preg_match('/\b(add|create)\b/', $text) === 1 && str_contains($text, 'task')) {
            return $this->addTasks($words, $quoted);
        }

        if (preg_match('/\b(overdue|late|past due)\b/', $text) === 1) {
            return $this->overdue();
        }

        if (preg_match('/\bhigh[- ]priority\b/', $text) === 1) {
            return $this->table('task-stats', [
                'measures' => ['tasks'],
                'by' => ['assignee'],
                'filters' => [['dimension' => 'priority', 'values' => ['high']]],
            ], fn (array $rows): string => self::highPrioritySaid($rows));
        }

        if (preg_match('/\b(per|by|each)\s+project\b/', $text) === 1) {
            return $this->table('task-stats', ['measures' => ['tasks', 'done_share'], 'by' => ['project']], fn (array $rows): string => self::projectsSaid($rows));
        }

        if (preg_match('/\b(per|by|each)\s+week\b|\bweekly\b/', $text) === 1) {
            return $this->table('completion-stats', ['measures' => ['tasks'], 'grain' => 'week', 'since' => '-8w'], fn (array $rows): string => self::weeksSaid($rows));
        }

        if (preg_match('/\b(per|by|each)\s+(person|assignee|member)\b|\bwho has\b|\bworkload\b/', $text) === 1) {
            return $this->table('tasks-by-assignee', [], fn (array $rows): string => self::busiest($rows));
        }

        if (preg_match('/\b(per|each)\s+day\b|\bcompleted\b|\bpace\b|\bvelocity\b/', $text) === 1) {
            return $this->table('completed-per-day', [], fn (array $rows): string => self::pace($rows));
        }

        if (preg_match('/\b(by status|per column|each column|chart|graph)\b/', $text) === 1) {
            return $this->table('tasks-by-status', [], fn (array $rows): string => self::columnsSaid($rows));
        }

        if (preg_match('/(how are we|how is the board|how\'s the board|summar|status|progress)/', $text) === 1) {
            return $this->summary();
        }

        return $this->help();
    }

    /**
     * "What's overdue?": list-tasks with the overdue filter, then name what it found, as a Markdown list under a bold
     * count, the way a model formats a list.
     *
     * @return Generator<int, ToolCall, string, string>
     */
    private function overdue(): Generator
    {
        $answer = yield $this->call('list-tasks', ['overdue' => true]);
        $tasks = self::tasks($answer);

        if ($tasks === null) {
            return "I couldn't look that up: ".self::sentence($answer);
        }

        if ($tasks === []) {
            return 'Nothing is overdue. Every open task is on time.';
        }

        $lines = array_map(fn (array $task): string => '- '.self::md($task['title']).' ('.implode(', ', array_filter([
            isset($task['assignee']['name']) ? self::md($task['assignee']['name']) : 'unassigned',
            isset($task['due_on']) ? 'due '.CarbonImmutable::parse($task['due_on'])->format('j M') : null,
        ])).')', $tasks);

        return (count($tasks) === 1 ? '**One task** is overdue:' : '**'.count($tasks).' tasks** are overdue:')."\n\n".implode("\n", $lines);
    }

    /**
     * "Add three launch tasks for Marcus": one create-task per task, each with a priority and a due date, so none waits
     * for a form. When the name is not a member's full name or email, the first call is refused with the team's members
     * listed; the planner picks the one the person meant and carries on, as a model reading that answer would.
     *
     * @return Generator<int, ToolCall, string, string>
     */
    private function addTasks(string $words, ?string $quoted): Generator
    {
        $titles = $quoted !== null ? [$quoted] : array_slice(self::LAUNCH_TASKS, 0, self::howMany($words));
        $assignee = self::assigneeIn($words, $this->assistant->user->email);

        $added = [];
        $refusals = [];

        foreach ($titles as $index => $title) {
            $input = array_filter([
                'title' => $title,
                'assignee' => $assignee,
                'priority' => 'normal',
                'due_on' => today()->addDays(3 + 2 * $index)->toDateString(),
            ]);

            $answer = yield $this->call('create-task', $input);

            if (! self::isDone($answer) && $assignee !== null && ($member = self::memberFromListing($answer, $assignee)) !== null) {
                $assignee = $member['email'];
                $answer = yield $this->call('create-task', [...$input, 'assignee' => $assignee]);
            }

            if (self::isDone($answer)) {
                $added[] = '“'.self::md($title).'”';
            } else {
                $refusals[] = self::sentence($answer);
            }
        }

        $for = $assignee === null ? '' : ' for '.self::md($this->assistant->team->findMember($assignee)->name ?? $assignee);

        return match (true) {
            $refusals === [] && count($added) === 1 => "I added {$added[0]}{$for}. It's in To do.",
            $refusals === [] => 'I added '.count($added)." tasks{$for}: ".Str::of(implode(', ', $added))->replaceLast(', ', ' and ').'. They are in To do.',
            $added === [] => "I couldn't add that: ".$refusals[0],
            default => 'I added '.count($added).' of '.count($titles)." tasks{$for}. One was refused: ".$refusals[0],
        };
    }

    /**
     * "Add a task for Marcus to review the pricing page": one create-task with the title and the assignee only, as a
     * model calls it when the person gave no priority or due date. The call waits for the person, who fills them in a
     * form the server builds, with the title and Marcus already filled; the turn ends there, and answered() replies once
     * they submit, decline or close it. The planner names Marcus by his full name, as a model reading the team's members
     * in its instructions does, so the form opens with him selected.
     *
     * @return Generator<int, ToolCall, string, string>
     */
    private function addTask(string $title, ?string $name): Generator
    {
        $answer = yield $this->call('create-task', array_filter([
            'title' => $title,
            'assignee' => $name === null ? null : $this->fullName($name),
        ]));

        // Reached only when the call did not wait for the person: the action ran it, or refused it, straight away.
        return self::isDone($answer) ? 'I added “'.self::md($title)."”. It's in To do." : "I couldn't add that: ".self::sentence($answer);
    }

    /**
     * "Move 'Design the empty states' to done": complete-task names the task by its title.
     *
     * @return Generator<int, ToolCall, string, string>
     */
    private function completeTask(string $title): Generator
    {
        $answer = yield $this->call('complete-task', ['title' => $title]);

        return self::isDone($answer)
            ? 'Done. “'.self::md($title).'” is in the Done column now.'
            : "I couldn't move “".self::md($title).'”: '.self::sentence($answer);
    }

    /**
     * "Delete 'Order new office chairs'": find the task, then call delete-task. For an owner or admin the call pauses
     * on a confirmation card and the turn ends there; answered() replies once the person confirms or declines. A member's
     * or viewer's assistant has no delete-task, so laravel/ai answers "does not exist" and next() replies with
     * unavailable().
     *
     * @return Generator<int, ToolCall, string, string>
     */
    private function deleteTask(string $title): Generator
    {
        $this->subject = $title;

        $answer = yield $this->call('search-tasks', ['query' => $title]);
        $found = self::tasks($answer) ?? [];
        $task = collect($found)->first(fn (array $task): bool => mb_strtolower($task['title']) === mb_strtolower($title)) ?? ($found[0] ?? null);

        if ($task === null) {
            return "I couldn't find a task called “".self::md($title).'”.';
        }

        $this->subject = $task['title'];

        $answer = yield $this->call('delete-task', ['task' => $task['id']]);

        // Reached only when the call did not wait for the person: the action refused it straight away.
        return "I couldn't delete “".self::md($task['title']).'”: '.self::sentence($answer);
    }

    /**
     * The person answered what the turn waited on, and laravel/ai resumed the paused call: a delete-task card, or a
     * create-task form. Read what it answered and say what happened.
     *
     * @return Generator<int, ToolCall, string, string>
     */
    private function answered(): Generator
    {
        yield from [];

        $answered = collect($this->results)->last(fn (ToolResult $result): bool => in_array($result->name, ['delete-task', 'create-task'], true));

        return match ($answered?->name) {
            'delete-task' => $this->deleted($answered),
            'create-task' => self::filledIn($answered),
            default => "There's nothing waiting for an answer.",
        };
    }

    /**
     * The reply to a confirmed or declined delete, naming the task from the search that found it.
     */
    private function deleted(ToolResult $delete): string
    {
        $found = collect($this->results)
            ->filter(fn (ToolResult $result): bool => $result->name === 'search-tasks')
            ->flatMap(fn (ToolResult $result): array => self::tasks($result->text()) ?? [])
            ->firstWhere('id', $delete->arguments['task'] ?? null);

        $task = isset($found['title']) ? '“'.self::md($found['title']).'”' : 'the task';

        return match (true) {
            $delete->denied => "Okay, I left {$task} on the board.",
            self::isDone($delete->text()) => "Deleted {$task}. It's off the board.",
            default => "I couldn't delete {$task}: ".self::sentence($delete->text()),
        };
    }

    /**
     * The reply once the person answered a create-task form. The model reads which fields they filled, never what they
     * wrote, so the reply names no title, date or person: they may have changed any of them.
     */
    private static function filledIn(ToolResult $create): string
    {
        // "The person filled in: title, priority, due_on, assignee. Done.": the action's own sentence follows the fields.
        $answer = (string) preg_replace('/^The person filled in: [^.]*\.\s*/', '', $create->text());

        return match (true) {
            $create->denied && str_contains($answer, 'closed the form') => 'No problem, I left it for now. Ask me again when you have the details.',
            $create->denied => "Okay, I didn't add it.",
            self::isDone($answer) => "Done. I added the task with the details you filled in, and it's in To do.",
            default => "I couldn't add it: ".self::sentence($answer),
        };
    }

    /**
     * "How are we doing?": summarize-board, read back.
     *
     * @return Generator<int, ToolCall, string, string>
     */
    private function summary(): Generator
    {
        $answer = yield $this->call('summarize-board', []);
        $counts = self::data($answer);

        if ($counts === null || ! isset($counts['total'])) {
            return "I couldn't count the board: ".self::sentence($answer);
        }

        return "{$counts['total']} tasks: {$counts['todo']} to do, {$counts['doing']} doing, {$counts['done']} done. "
            ."{$counts['overdue']} overdue, {$counts['due_soon']} due in the next seven days, {$counts['unassigned']} open and unassigned.";
    }

    /**
     * "Tasks by status", "Who has the most tasks?", "Tasks completed per day": one call of a table action, whose rows the
     * person sees as a table and a chart. The planner reads the short copy after "---", as a model does, and says what
     * stands out instead of repeating the rows.
     *
     * @param  array<string, mixed>  $arguments
     * @param  Closure(list<array<string, mixed>>): string  $say
     * @return Generator<int, ToolCall, string, string>
     */
    private function table(string $tool, array $arguments, Closure $say): Generator
    {
        $answer = yield $this->call($tool, $arguments);
        $rows = self::data($answer)['rows'] ?? null;

        return is_array($rows) ? $say(array_values(array_filter($rows, is_array(...)))) : "I couldn't look that up: ".self::sentence($answer);
    }

    /**
     * What stands out in the columns table: the fullest column, and the overdue tasks.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function columnsSaid(array $rows): string
    {
        $fullest = collect($rows)->sortByDesc('tasks')->first();
        $overdue = (int) collect($rows)->sum('overdue');

        return "{$fullest['status']} holds the most tasks, {$fullest['tasks']}"
            .($overdue === 0 ? ', and nothing is overdue.' : ', and '.($overdue === 1 ? 'one task is' : "{$overdue} tasks are").' overdue.');
    }

    /**
     * What stands out in the assignees table: who holds the most open tasks.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function busiest(array $rows): string
    {
        $first = $rows[0] ?? null;

        return $first === null ? 'Nobody holds a task yet.' : self::md($first['assignee'])." holds the most open tasks, {$first['open']}.";
    }

    /**
     * What stands out in the pace table: how many were completed, and the busiest day.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function pace(array $rows): string
    {
        $total = (int) collect($rows)->sum('completed');
        $best = collect($rows)->sortByDesc('completed')->first();

        if ($total === 0) {
            return 'Nothing was completed in these days.';
        }

        $day = CarbonImmutable::parse($best['day'])->format('j M');

        return $total === 1 ? "One task was completed, on {$day}." : "{$total} tasks were completed, most on {$day}.";
    }

    /**
     * What stands out in the high-priority tasks per assignee: who holds the most.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function highPrioritySaid(array $rows): string
    {
        $first = collect($rows)->first(fn (array $row): bool => $row['assignee'] !== null);

        return $first === null ? 'No high-priority task has an assignee.' : self::md($first['assignee'])." holds the most high-priority tasks, {$first['tasks']}.";
    }

    /**
     * What stands out in the tasks per project: the biggest project, and how much of it is done.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function projectsSaid(array $rows): string
    {
        $first = collect($rows)->first(fn (array $row): bool => $row['project'] !== null);

        return $first === null
            ? 'No task belongs to a project yet.'
            : self::md($first['project'])." has the most tasks, {$first['tasks']}, and ".round((float) $first['done_share'] * 100).'% of them are done.';
    }

    /**
     * What stands out in the completions per week: how many, and the busiest week.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function weeksSaid(array $rows): string
    {
        $total = (int) collect($rows)->sum('tasks');
        $best = collect($rows)->sortByDesc('tasks')->first();

        return $total === 0
            ? 'No task was completed in these weeks.'
            : ($total === 1 ? 'One task was' : "{$total} tasks were").' completed in these '.count($rows).' weeks, most in the week of '.CarbonImmutable::parse($best['completed'])->format('j M').'.';
    }

    /**
     * Anything else: a plain reply that says what demo mode can do, and how to reach a real model: on the hosted demo,
     * a person's own MCP client; on a copy, a key in .env.
     *
     * @return Generator<int, ToolCall, string, string>
     */
    private function help(): Generator
    {
        yield from [];

        $requests = 'I follow only a few requests: '
            ."“What's overdue?”, “Add three launch tasks for Marcus”, “Add a task for Marcus to review the pricing page”, "
            ."“Move 'Design the empty states' to done”, "
            ."“Delete 'Order new office chairs'”, “How are we doing?”, and the charts “Tasks by status”, "
            .'“Who has the most tasks?” and “Tasks completed per day”, and the open questions “High-priority tasks per person”, '
            .'“Tasks per project” and “Tasks completed per week”.';

        if (config('demo.hosted')) {
            return "This is a scripted demo. My replies follow a script, and the actions, permissions and cards are real. {$requests} "
                .'To use a real model on this board, connect Claude over MCP from the AI clients page.';
        }

        return "I'm in demo mode, so my replies are scripted and {$requests} Add OPENROUTER_API_KEY to .env to talk to a real model.";
    }

    /**
     * The reply when laravel/ai said a tool does not exist for this person.
     */
    private function unavailable(string $tool): string
    {
        $role = $this->assistant->user->teamRole($this->assistant->team)?->label() ?? 'not a member';
        $team = self::md($this->assistant->team->name);
        $subject = self::md((string) $this->subject);

        return match ($tool) {
            'delete-task' => "I can't delete tasks here. You're a {$role} in {$team}, and I only get the tools your role allows, so “{$subject}” is still on the board.",
            'create-task' => "I can't add tasks here. You're a {$role} in {$team}, and I only get the tools your role allows, so nothing changed.",
            'complete-task', 'update-task' => "I can't change tasks here. You're a {$role} in {$team}, and I only get the tools your role allows, so nothing changed.",
            default => "I can't do that: {$tool} isn't one of my tools for your role, so nothing changed.",
        };
    }

    /**
     * The full name of the one member of this team whose name or email starts with what the person said, else the words
     * as said.
     */
    private function fullName(string $said): string
    {
        $needle = mb_strtolower($said);
        $members = $this->assistant->team->members()->get()->filter(fn (User $user): bool => str_starts_with(mb_strtolower($user->name), $needle)
            || str_starts_with(mb_strtolower($user->email), $needle));

        return $members->count() === 1 ? $members->first()->name : $said;
    }

    /**
     * A tool call with a fresh id.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function call(string $tool, array $arguments): ToolCall
    {
        return new ToolCall('call_'.Str::lower((string) Str::ulid()), $tool, $arguments);
    }

    /**
     * Wait a little, like a model does, unless the pace is set to zero (the tests do).
     */
    private function pause(int $beats = 1): void
    {
        $milliseconds = (int) config('ai.demo_pace_ms', 0) * $beats;

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * The tool results sent after the newest user message: this turn's, not the history's.
     *
     * @param  array<int, Message>  $messages
     * @return list<ToolResult>
     */
    private static function resultsOfThisTurn(array $messages): array
    {
        $results = [];

        foreach (array_reverse($messages) as $message) {
            if ($message->role === MessageRole::User) {
                break;
            }

            if ($message instanceof ToolResultMessage) {
                array_unshift($results, ...$message->toolResults->all());
            }
        }

        return $results;
    }

    /**
     * A title or a name, safe to put in a Markdown reply: on one line, with the characters that would make a link, an
     * image, emphasis, code, a heading, a table or HTML backslash-escaped, so it reads exactly as it was typed.
     */
    private static function md(string $text): string
    {
        $line = (string) preg_replace('/\s+/u', ' ', $text);

        return (string) preg_replace('/([\\\\`*_\[\]()<>#~|!])/', '\\\\$1', $line);
    }

    /**
     * Whether the answer is a success: the package's "Done." or an action's own reply that starts with it.
     */
    private static function isDone(string $answer): bool
    {
        return str_starts_with($answer, 'Done');
    }

    /**
     * The sentence of an answer, before any data.
     */
    private static function sentence(string $answer): string
    {
        return trim(Str::before($answer, "\n---\n"));
    }

    /**
     * The data a Read or a refusal lists after "---", or null when there is none.
     *
     * @return array<array-key, mixed>|null
     */
    private static function data(string $answer): ?array
    {
        $data = str_contains($answer, "\n---\n") ? json_decode(Str::after($answer, "\n---\n"), true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * The tasks a Read answered with, or null when it answered with none: a refusal.
     *
     * @return list<array<array-key, mixed>>|null
     */
    private static function tasks(string $answer): ?array
    {
        $tasks = self::data($answer)['tasks'] ?? null;

        return is_array($tasks) ? array_values(array_filter($tasks, is_array(...))) : null;
    }

    /**
     * The member a refusal lists ("Name <email>") whose name or email starts with what the person said, when exactly
     * one does.
     *
     * @return array{name: string, email: string}|null
     */
    private static function memberFromListing(string $answer, string $said): ?array
    {
        $said = mb_strtolower($said);

        $members = [];

        foreach (self::data($answer) ?? [] as $entry) {
            if (is_string($entry) && preg_match('/^(.+) <(.+)>$/', $entry, $parts) === 1
                && (str_starts_with(mb_strtolower($parts[1]), $said) || str_starts_with(mb_strtolower($parts[2]), $said))) {
                $members[] = ['name' => $parts[1], 'email' => $parts[2]];
            }
        }

        return count($members) === 1 ? $members[0] : null;
    }

    /**
     * The first quoted phrase, in straight or curly quotes.
     */
    private static function quoted(string $words): ?string
    {
        return preg_match('/[\'"‘“]([^\'"’”]{2,120})[\'"’”]/u', $words, $match) === 1 ? trim($match[1]) : null;
    }

    /**
     * The title in "move the design task to done" when nothing is quoted.
     */
    private static function titleBeforeDone(string $words): ?string
    {
        return preg_match('/\b(?:move|mark|complete|finish)\s+(.+?)\s+(?:to|as)\s+done\b/iu', $words, $match) === 1 ? trim($match[1]) : null;
    }

    /**
     * How many tasks were asked for: a number or a word up to five, one by default.
     */
    private static function howMany(string $words): int
    {
        $numbers = ['a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];

        if (preg_match('/\b(add|create)\s+(\d+|a|an|one|two|three|four|five)\b/i', $words, $match) !== 1) {
            return 1;
        }

        return max(1, min(5, (int) ($numbers[mb_strtolower($match[2])] ?? $match[2])));
    }

    /**
     * Who the tasks are for: the words after "for" at the end, "me" for the person asking, or nobody.
     */
    private static function assigneeIn(string $words, string $me): ?string
    {
        if (preg_match('/\bfor\s+([^\s,.!?]+(?:\s+[^\s,.!?]+)?)\s*[.!?]*\s*$/u', trim($words), $match) !== 1) {
            return null;
        }

        return mb_strtolower($match[1]) === 'me' ? $me : $match[1];
    }
}
