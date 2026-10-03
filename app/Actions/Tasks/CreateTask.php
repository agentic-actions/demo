<?php

namespace App\Actions\Tasks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Ask;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use App\Actions\Concerns\InteractsWithBoard;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TeamPermission;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * Web form, JSON, CLI and the assistant's "default" toolset. People and projects are named, never numbered, so the
 * same input reads naturally from a form select and from a model.
 *
 * Every task a model adds has a priority and a due date, and $askForMissing lets the person give them: a call that leaves
 * them out opens a form in the chat, or in an MCP client that shows forms, instead of running. requiredForAgents() says
 * so for models alone, not an agentSchema(), which would drop the generated route the board's form posts to.
 */
#[Expose]
final class CreateTask extends Action
{
    use InteractsWithBoard;

    protected string $description = 'Create a task on the current team\'s board. Name the assignee by email or full name and the project by its name.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['tasks', 'summary'];

    protected bool $askForMissing = true;

    /**
     * The new task's fields. The copilot's form shows a field's description as its help text, so the fields it asks
     * about are described for a person as well as a model.
     */
    public function schema(JsonSchema $schema): array
    {
        $fields = $this->taskFields($schema, titleRequired: true);

        $fields['priority']->description('How urgent it is: low, normal or high.');
        $fields['due_on']->description('The day it should be done by.');
        $fields['assignee']->description('Who does it: a member of this team, by email or full name.');

        return $fields;
    }

    /**
     * A model's call must carry a priority and a due date, the copilot's and an MCP client's alike, while the board's
     * form and the CLI leave them optional. They are offered to models as required, a call that leaves them out is
     * refused naming them, and $askForMissing turns that refusal into a form that marks them required.
     *
     * @return list<string>
     */
    public function requiredForAgents(): array
    {
        return ['priority', 'due_on'];
    }

    /**
     * The form for a model's call that left the priority or the due date out: the title, the priority and the assignee
     * shown for review, the team's members to pick from by full name (a value the assignee field takes), and normal
     * priority unless the model or the person picks another. Runs on a fresh instance with the context alone, so it
     * reads only this team.
     */
    public function ask(Ask $ask, ActionContext $context): Ask
    {
        $members = $this->team($context)->members()->orderBy('name')->pluck('users.name', 'users.name')->all();
        $priorities = collect(TaskPriority::cases())->mapWithKeys(fn (TaskPriority $priority): array => [$priority->value => __($priority->label())])->all();

        return $ask
            ->message(__('Give this task a due date and a priority, and I\'ll add it.'))
            ->confirm('title', 'priority', 'assignee')
            ->choices('assignee', $members)
            ->choices('priority', $priorities)
            ->default('priority', TaskPriority::Normal->value)
            ->textarea('description');
    }

    /**
     * The task as the board shows it.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['task' => self::taskOutput($schema)->required()];
    }

    /**
     * Owners, admins and members create tasks; viewers do not.
     */
    public function authorize(ActionContext $context): bool
    {
        return $this->allows($context, TeamPermission::CreateTask);
    }

    /**
     * Save the task in this team. On the hosted demo, a team holds at most demo.caps.tasks_per_team tasks.
     *
     * @return array{task: array<string, mixed>}
     *
     * @throws Refusal when the hosted demo team is full
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $team = $this->team($context);

        $this->ensureRoomFor($team->tasks()->count(), 'tasks_per_team', 'This demo team holds :max tasks, as many as it can. Delete a few to add more.');

        $task = new Task([
            'title' => $input->string('title')->trim()->toString(),
            'description' => $input->input('description'),
            'priority' => $input->enum('priority', TaskPriority::class) ?? TaskPriority::Normal,
            'due_on' => $input->input('due_on'),
        ]);

        $task->team()->associate($team);
        $task->creator()->associate($context->actor(User::class));
        $task->assignee()->associate($this->memberNamed($team, $input->input('assignee')));
        $task->project()->associate($this->projectNamed($team, $input->input('project')));
        $task->moveTo($input->enum('status', TaskStatus::class) ?? TaskStatus::Todo);
        $task->save();

        return ['task' => $task->toBoardArray()];
    }

    /**
     * The copilot row: "Creating a task…" while it runs, "Created a task" once it succeeded.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Created a task') : __('Creating a task…');
    }
}
