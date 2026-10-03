<?php

namespace App\Actions\Tasks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use App\Actions\Concerns\InteractsWithBoard;
use App\Enums\TaskStatus;
use App\Enums\TeamPermission;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * The agent-vocabulary example. Code and the CLI name the task by number (schema()); a model names it by its title
 * (agentSchema()), and fromAgent() finds it among this team's open tasks. Because the action has an agentSchema(),
 * a bare #[Expose] gives it no generated route: the board moves cards with update-task instead.
 */
#[Expose]
final class CompleteTask extends Action
{
    use InteractsWithBoard;

    protected string $description = 'Mark one of the current team\'s open tasks as done, named by its title.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['tasks', 'summary'];

    /**
     * The canonical input: the task number.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->integer()->min(1)->required(),
        ];
    }

    /**
     * What a model is offered instead: the title a person would say.
     */
    public function agentSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->min(1)->max(120)->required()->description('The task\'s title, or enough of it to pick out one open task.'),
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
     * Find the open task the model named: an exact title first, then the only title that contains the words.
     *
     * @return array{task: int}
     *
     * @throws Refusal listing the open titles when none or several match
     */
    public function fromAgent(ValidatedInput $input, ActionContext $context): array
    {
        $open = Task::query()->whereBelongsTo($this->team($context))->where('status', '!=', TaskStatus::Done->value);
        $title = mb_strtolower($input->string('title')->trim()->toString());

        $exact = (clone $open)->whereRaw('LOWER(title) = ?', [$title])->pluck('id');
        $matches = $exact->isNotEmpty() ? $exact : (clone $open)->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", ['%'.self::escapeLike($title).'%'])->pluck('id');

        if ($matches->count() !== 1) {
            throw Refusal::make($matches->isEmpty() ? 'No open task has that title.' : 'Several open tasks match that title. Use the full title.')
                ->on('title')
                ->listing(array_values((clone $open)->orderBy('title')->limit(30)->pluck('title')->all()));
        }

        return ['task' => (int) $matches->first()];
    }

    /**
     * The kit's UpdateTask permission. It runs before fromAgent(), so a viewer's call never looks at titles.
     */
    public function authorize(ActionContext $context): bool
    {
        return $this->allows($context, TeamPermission::UpdateTask);
    }

    /**
     * Move the task to done.
     *
     * @return array{task: array<string, mixed>}
     *
     * @throws Refusal when the task is already done
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $task = $context->find(Task::class, $input->integer('task'));

        if ($task->status === TaskStatus::Done) {
            throw Refusal::make('That task is already done.');
        }

        $task->moveTo(TaskStatus::Done);
        $task->save();

        return ['task' => $task->toBoardArray()];
    }

    /**
     * The sentence the model reads. The title stays out of it: a model reads only sentences the app wrote.
     */
    public function modelReply(mixed $result, ActionContext $context): string
    {
        return 'Done. The task is now in the Done column.';
    }

    /**
     * The copilot row: "Moving a task to done…" while it runs, "Moved a task to done" once it succeeded.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Moved a task to done') : __('Moving a task to done…');
    }
}
