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
use Illuminate\Database\Query\Builder;

/**
 * A table of who holds what: each assignee's open and done tasks and the share done, busiest first. Unassigned tasks
 * are one row of their own.
 */
#[Expose]
final class TasksByAssignee extends Action implements ShowsTable
{
    use InteractsWithBoard;

    protected string $description = 'Show the person a table and chart of the current team\'s tasks per assignee: open, done, and the share done, busiest first. Use it for "who has the most tasks", workload, or "how is each person doing".';

    protected ?Effect $effect = Effect::Read;

    /**
     * The assignee, their open and done tasks, and the share done.
     */
    public function columns(): array
    {
        return [
            Column::text('assignee', __('Assignee')),
            Column::integer('open', __('Open')),
            Column::integer('done', __('Done')),
            Column::percent('done_share', __('Done share'))->description('Done tasks out of all their tasks.'),
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
     * The team's tasks grouped by assignee, busiest first; the package reads at most views.max_rows of them.
     */
    public function handle(ActionContext $context): Builder
    {
        $done = TaskStatus::Done->value;

        return Task::query()
            ->whereBelongsTo($this->team($context))
            ->leftJoin('users', 'users.id', '=', 'tasks.assignee_id')
            ->toBase()
            ->selectRaw('coalesce(users.name, ?) as assignee', [__('Unassigned')])
            ->selectRaw('sum(case when tasks.status != ? then 1 else 0 end) as open', [$done])
            ->selectRaw('sum(case when tasks.status = ? then 1 else 0 end) as done', [$done])
            ->selectRaw('avg(case when tasks.status = ? then 1.0 else 0 end) as done_share', [$done])
            ->groupBy('tasks.assignee_id', 'users.name')
            ->orderByDesc('open')
            ->orderBy('assignee');
    }

    /**
     * The copilot row.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Counted the tasks per person') : __('Counting the tasks per person…');
    }
}
