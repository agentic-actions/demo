<?php

namespace App\Actions\Tasks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Actions\Concerns\InteractsWithBoard;
use App\Enums\TeamPermission;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Destructive: other people rely on the task, and nobody can bring it back. The board's bin runs it on the web route.
 * The assistant is offered it through the "default" toolset, and each call it makes waits for the person to confirm a
 * card the server builds from the task (approvalSummary()). MCP never serves it, since MCP has no confirmation step.
 */
#[Expose(web: true, agents: ['default'])]
final class DeleteTask extends Action
{
    use InteractsWithBoard;

    protected string $description = 'Delete a task from the current team\'s board for good. Get the task number from list-tasks or search-tasks.';

    protected ?Effect $effect = Effect::Destructive;

    protected array $touches = ['tasks', 'summary'];

    /**
     * The task to delete.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->integer()->min(1)->required()->description('The task number, as list-tasks and search-tasks return it in "id".'),
        ];
    }

    /**
     * authorize() needs the task, so it runs only once the model calls the tool. This keeps the tool off the list of a
     * member's or a viewer's assistant. The board's own route still answers them "You are not allowed to do this."
     */
    public function shouldRegister(ActionContext $context): bool
    {
        return ! $context->surface->isModelDriven() || $this->allows($context, TeamPermission::DeleteTask);
    }

    /**
     * Owners and admins only, and only a task of this team: another team's task number reads as not found.
     */
    public function authorize(ActionContext $context, ValidatedInput $input): bool
    {
        if (! $this->allows($context, TeamPermission::DeleteTask)) {
            return false;
        }

        $context->find(Task::class, $input->integer('task'));

        return true;
    }

    /**
     * The confirmation card's sentence. The task itself is in approvalSummary().
     */
    public function approvalReason(ActionContext $context): string
    {
        return __('Delete this task? This cannot be undone.');
    }

    /**
     * What the person confirms: the task authorize() allowed, found again through the team scope.
     *
     * @return array<string, string>
     */
    public function approvalSummary(ActionContext $context, ValidatedInput $input): array
    {
        $task = $context->find(Task::class, $input->integer('task'));

        return [
            __('Task') => $task->title,
            __('Project') => $task->project->name ?? __('No project'),
            __('Assignee') => $task->assignee->name ?? __('Unassigned'),
        ];
    }

    /**
     * Delete it.
     */
    public function handle(ActionContext $context, ValidatedInput $input): void
    {
        $context->find(Task::class, $input->integer('task'))->delete();
    }

    /**
     * The copilot row: "Deleting a task…" while it runs, "Deleted a task" once it succeeded.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Deleted a task') : __('Deleting a task…');
    }
}
