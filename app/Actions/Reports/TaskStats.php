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

/**
 * The team's tasks as a dataset: the model asks its own question within these names, such as high-priority tasks per
 * person or tasks added per week, and the package writes the query inside the team. The time is when a task was added.
 */
#[Expose]
final class TaskStats extends Dataset
{
    protected string $description = 'Answer a question about the current team\'s tasks with a table and chart: count them by when they were added, status, priority, assignee or project, filter them, and compare with the previous period. Use it when no other report fits, such as high-priority tasks per person or tasks added per week.';

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
     * When a task was added, its column, priority, assignee and project.
     */
    public function dimensions(): array
    {
        return [
            Dimension::time('added', __('Added'), 'created_at'),
            Dimension::enum('status', __('Column'), 'status', TaskStatus::class),
            Dimension::enum('priority', __('Priority'), 'priority', TaskPriority::class),
            Dimension::text('assignee', __('Assignee'), 'assignee.name'),
            Dimension::text('project', __('Project'), 'project.name'),
        ];
    }

    /**
     * How many tasks, how many of them are done, and the share done.
     */
    public function measures(): array
    {
        return [
            Measure::count('tasks', __('Tasks')),
            Measure::count('done', __('Done'))->where('status', TaskStatus::Done),
            Measure::ratio('done_share', __('Done share'), 'done', 'tasks'),
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
     * The copilot row.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Counted the tasks') : __('Counting the tasks…');
    }
}
