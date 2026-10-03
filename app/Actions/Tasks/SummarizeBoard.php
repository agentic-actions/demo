<?php

namespace App\Actions\Tasks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Actions\Concerns\InteractsWithBoard;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The numbers above the board, and the assistant's answer to "how are we doing?".
 */
#[Expose]
final class SummarizeBoard extends Action
{
    use InteractsWithBoard;

    protected string $description = 'Count the current team\'s tasks by status, and how many are overdue, due within the next seven days, or unassigned.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The counts.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'total' => $schema->integer()->required(),
            'todo' => $schema->integer()->required(),
            'doing' => $schema->integer()->required(),
            'done' => $schema->integer()->required(),
            'overdue' => $schema->integer()->required()->description('Past their due date and not done.'),
            'due_soon' => $schema->integer()->required()->description('Not done, due today or within the next seven days.'),
            'unassigned' => $schema->integer()->required()->description('Not done and assigned to nobody.'),
        ];
    }

    /**
     * Every member of the team may read the summary.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * Count the team's tasks.
     *
     * @return array{total: int, todo: int, doing: int, done: int, overdue: int, due_soon: int, unassigned: int}
     */
    public function handle(ActionContext $context): array
    {
        $tasks = Task::query()->whereBelongsTo($this->team($context));
        $byStatus = (clone $tasks)->toBase()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $open = (clone $tasks)->where('status', '!=', TaskStatus::Done->value);

        return [
            'total' => (int) $byStatus->sum(),
            'todo' => (int) ($byStatus[TaskStatus::Todo->value] ?? 0),
            'doing' => (int) ($byStatus[TaskStatus::Doing->value] ?? 0),
            'done' => (int) ($byStatus[TaskStatus::Done->value] ?? 0),
            'overdue' => (clone $tasks)->overdue()->count(),
            'due_soon' => (clone $open)->whereDate('due_on', '>=', today())->whereDate('due_on', '<=', today()->addDays(7))->count(),
            'unassigned' => (clone $open)->whereNull('assignee_id')->count(),
        ];
    }

    /**
     * The copilot row: "Counting the board…" while it runs, "Counted the board" once it succeeded.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Counted the board') : __('Counting the board…');
    }
}
