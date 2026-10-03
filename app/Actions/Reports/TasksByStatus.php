<?php

namespace App\Actions\Reports;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Views\Column;
use AgenticActions\Views\ShowsTable;
use App\Actions\Concerns\InteractsWithBoard;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;

/**
 * A table of the board's columns: how many tasks each holds, and how many of those are overdue. In the copilot the
 * person sees it as a table and a bar chart; the model reads a short copy.
 */
#[Expose]
final class TasksByStatus extends Action implements ShowsTable
{
    use InteractsWithBoard;

    protected string $description = 'Show the person a table and chart of the current team\'s tasks per board column (To do, Doing, Done), with how many of each are overdue. Use it for "how many tasks are in each column" or a chart of the board.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The column, its tasks and its overdue tasks.
     */
    public function columns(): array
    {
        return [
            Column::text('status', __('Column')),
            Column::integer('tasks', __('Tasks')),
            Column::integer('overdue', __('Overdue'))->description('Past their due date and not done.'),
        ];
    }

    /**
     * Every member of the team may read the board.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * One row per column, in board order, empty columns included.
     *
     * @return list<array{status: string, tasks: int, overdue: int}>
     */
    public function handle(ActionContext $context): array
    {
        $tasks = Task::query()->whereBelongsTo($this->team($context));
        $counts = (clone $tasks)->toBase()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $overdue = (clone $tasks)->overdue()->toBase()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return array_map(fn (TaskStatus $status): array => [
            'status' => $status->label(),
            'tasks' => (int) ($counts[$status->value] ?? 0),
            'overdue' => (int) ($overdue[$status->value] ?? 0),
        ], TaskStatus::cases());
    }

    /**
     * The copilot row.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Counted the tasks per column') : __('Counting the tasks per column…');
    }
}
