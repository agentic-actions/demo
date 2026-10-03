<?php

namespace App\Actions\Reports;

use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Datasets\Dataset;
use AgenticActions\Datasets\Dimension;
use AgenticActions\Datasets\Measure;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The team's done tasks as a dataset, counted by when they were completed: a dataset has one time, so completions
 * need their own. scope() keeps the done tasks, inside the team the package already scoped the query to.
 */
#[Expose]
final class CompletionStats extends Dataset
{
    protected string $description = 'Answer a question about the tasks the current team completed, with a table and chart: count them by when they were completed, priority, assignee or project, filter them, and compare with the previous period. Use it for questions such as tasks completed per week or who completed the most this month.';

    protected string $model = Task::class;

    /**
     * With no dates in the call, the last twelve months: the whole of a young board, so the counts match it.
     */
    protected string $range = '-12m';

    /**
     * Assignees are users, whom no one team owns: their names are read without the team's scope, which every other
     * related row, such as a task's project, is read in.
     *
     * @var list<string>
     */
    protected array $shared = ['assignee'];

    /**
     * When a task was completed, its priority, assignee and project.
     */
    public function dimensions(): array
    {
        return [
            Dimension::time('completed', __('Completed'), 'completed_at'),
            Dimension::enum('priority', __('Priority'), 'priority', TaskPriority::class),
            Dimension::text('assignee', __('Assignee'), 'assignee.name'),
            Dimension::text('project', __('Project'), 'project.name'),
        ];
    }

    /**
     * How many tasks were completed.
     */
    public function measures(): array
    {
        return [
            Measure::count('tasks', __('Completed tasks')),
        ];
    }

    /**
     * Only the done tasks.
     *
     * @param  Builder<Model>  $query
     */
    public function scope(Builder $query, ActionContext $context): void
    {
        $query->where('status', TaskStatus::Done->value);
    }

    /**
     * Every member of the team may read the board.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * The copilot row.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Counted the completed tasks') : __('Counting the completed tasks…');
    }
}
