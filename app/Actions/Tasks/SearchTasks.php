<?php

namespace App\Actions\Tasks;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use App\Actions\Concerns\InteractsWithBoard;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ValidatedInput;

/**
 * The board's search box, and how the assistant finds a task number before it changes a task.
 */
#[Expose]
final class SearchTasks extends Action
{
    use InteractsWithBoard;

    protected string $description = 'Search the current team\'s tasks by words in their title or details. Returns up to 20 matches with their task numbers.';

    protected ?Effect $effect = Effect::Read;

    /**
     * The words to look for.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(2)->max(100)->required()->description('Words from the task\'s title or details.'),
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
     * Every member of the team may search its board.
     */
    public function authorize(ActionContext $context): bool
    {
        return $context->actor instanceof User;
    }

    /**
     * Up to 20 tasks whose title or details contain the words, open tasks first.
     *
     * @return array{tasks: list<array<string, mixed>>}
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $needle = '%'.self::escapeLike(mb_strtolower($input->string('query')->trim()->toString())).'%';

        $tasks = Task::query()
            ->whereBelongsTo($this->team($context))
            ->with(['assignee', 'project'])
            ->where(fn (Builder $query) => $query
                ->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$needle])
                ->orWhereRaw("LOWER(COALESCE(description, '')) LIKE ? ESCAPE '!'", [$needle]))
            ->orderByRaw("status = 'done'")
            ->orderBy('title')
            ->limit(20)
            ->get();

        return ['tasks' => array_values($tasks->map(fn (Task $task): array => $task->toBoardArray())->all())];
    }

    /**
     * The copilot row: "Searching tasks…" while it runs, "Searched tasks" once it succeeded.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Searched tasks') : __('Searching tasks…');
    }
}
