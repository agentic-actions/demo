<?php

namespace App\Actions\Tasks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Actions\Concerns\InteractsWithBoard;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ValidatedInput;

/**
 * The board's own data source: BoardController runs it in-process for the page, the same class answers the JSON
 * route, the CLI and the assistant. A Read, so the package refuses any write while it runs.
 */
#[Expose]
final class ListTasks extends Action
{
    use InteractsWithBoard;

    protected string $description = 'List the current team\'s tasks, highest priority and soonest due first. Every filter is optional.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The filters.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(TaskStatus::values())->nullable()->description('Only tasks in this column: todo, doing or done.'),
            'priority' => $schema->string()->enum(TaskPriority::values())->nullable()->description('Only tasks of this priority.'),
            'assignee' => $schema->string()->max(255)->nullable()->description('Only tasks assigned to this member, by email or full name. "me" means the person asking.'),
            'project' => $schema->string()->max(80)->nullable()->description('Only tasks of this project, by name.'),
            'overdue' => $schema->boolean()->description('true for only the tasks past their due date and not done.'),
        ];
    }

    /**
     * The matching tasks.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'tasks' => $schema->array()->items(self::taskOutput($schema))->required(),
        ];
    }

    /**
     * Every member of the team may read its board, viewers included.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * The team's tasks, filtered and sorted for the board.
     *
     * @return array{tasks: list<array<string, mixed>>}
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $team = $this->team($context);
        $assignee = $input->input('assignee');

        $tasks = Task::query()
            ->whereBelongsTo($team)
            ->with(['assignee', 'project'])
            ->when($input->enum('status', TaskStatus::class), fn (Builder $query, TaskStatus $status) => $query->where('status', $status->value))
            ->when($input->enum('priority', TaskPriority::class), fn (Builder $query, TaskPriority $priority) => $query->where('priority', $priority->value))
            ->when($assignee !== null, fn (Builder $query) => $query->where('assignee_id', mb_strtolower((string) $assignee) === 'me'
                ? $context->actor(User::class)->id
                : $this->memberNamed($team, (string) $assignee)?->id))
            ->when($input->filled('project'), fn (Builder $query) => $query->where('project_id', $this->projectNamed($team, $input->string('project')->toString())?->id))
            ->when($input->boolean('overdue'), fn (Builder $query) => $query->overdue())
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();

        return ['tasks' => array_values($tasks->map(fn (Task $task): array => $task->toBoardArray())->all())];
    }

    /**
     * The copilot row: "Listing tasks…" while it runs, "Listed tasks" once it succeeded.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Listed tasks') : __('Listing tasks…');
    }
}
