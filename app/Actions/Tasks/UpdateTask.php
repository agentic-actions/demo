<?php

namespace App\Actions\Tasks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Actions\Concerns\InteractsWithBoard;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TeamPermission;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Changes only the fields the caller sends: the board's status select sends one, the edit dialog sends them all, and a
 * model sends what the person asked for. The task is found through the team scope, so another team's task number
 * reads as not found.
 */
#[Expose]
final class UpdateTask extends Action
{
    use InteractsWithBoard;

    protected string $description = 'Change a task on the current team\'s board: move it to another status, reassign it, or edit its title, details, priority, due date or project. Send only the fields to change. Get the task number from list-tasks or search-tasks.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['tasks', 'summary'];

    /**
     * The task, and the fields to change.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->integer()->min(1)->required()->description('The task number, as list-tasks and search-tasks return it in "id".'),
            ...$this->taskFields($schema, titleRequired: false),
        ];
    }

    /**
     * The task as the board shows it.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['task' => self::taskOutput($schema)->required()];
    }

    /**
     * The kit's UpdateTask permission. It takes no input, so the assistant's tool list already leaves update-task out
     * for a viewer. Which task is fine to touch is the team scope's job in handle().
     */
    public function authorize(ActionContext $context): bool
    {
        return $this->allows($context, TeamPermission::UpdateTask);
    }

    /**
     * Apply the fields that were sent.
     *
     * @return array{task: array<string, mixed>}
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $team = $this->team($context);

        // Scoped to the team: another team's task number throws, and every surface answers "not found".
        $task = $context->find(Task::class, $input->integer('task'));

        if ($input->has('title')) {
            $task->title = $input->string('title')->trim()->toString();
        }

        if ($input->has('description')) {
            $task->description = $input->input('description');
        }

        if ($input->has('priority')) {
            $task->priority = $input->enum('priority', TaskPriority::class) ?? $task->priority;
        }

        if ($input->has('due_on')) {
            $task->due_on = $input->input('due_on');
        }

        if ($input->has('assignee')) {
            $task->assignee()->associate($this->memberNamed($team, $input->input('assignee')));
        }

        if ($input->has('project')) {
            $task->project()->associate($this->projectNamed($team, $input->input('project')));
        }

        if ($input->has('status')) {
            $task->moveTo($input->enum('status', TaskStatus::class) ?? $task->status);
        }

        $task->save();

        return ['task' => $task->toBoardArray()];
    }

    /**
     * The copilot row: "Updating a task…" while it runs, "Updated a task" once it succeeded.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Updated a task') : __('Updating a task…');
    }
}
