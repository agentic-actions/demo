<?php

namespace App\Actions\Reports;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Views\Column;
use AgenticActions\Views\ShowsTable;
use App\Actions\Concerns\InteractsWithBoard;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * A table of the tasks completed each day over the last few days, every day listed, quiet days as 0: a line chart of
 * the team's pace.
 */
#[Expose]
final class CompletedPerDay extends Action implements ShowsTable
{
    use InteractsWithBoard;

    protected string $description = 'Show the person a table and line chart of how many of the current team\'s tasks were completed each day, over the last few days. Use it for the team\'s pace, velocity, or "how much did we finish this week".';

    protected ?Effect $effect = Effect::Read;

    /**
     * How many days back.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->min(7)->max(90)->description('How many days back, today included. 14 when left out.'),
        ];
    }

    /**
     * The day and the tasks completed that day.
     */
    public function columns(): array
    {
        return [
            Column::date('day', __('Day')),
            Column::integer('completed', __('Completed')),
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
     * One row per day, oldest first.
     *
     * @return list<array{day: string, completed: int}>
     */
    public function handle(ActionContext $context, ValidatedInput $input): array
    {
        $days = $input->integer('days', 14);
        $from = today()->subDays($days - 1);

        $counts = Task::query()
            ->whereBelongsTo($this->team($context))
            ->where('completed_at', '>=', $from)
            ->toBase()
            ->selectRaw('date(completed_at) as day, COUNT(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        return array_map(fn ($day): array => [
            'day' => $day->toDateString(),
            'completed' => (int) ($counts[$day->toDateString()] ?? 0),
        ], iterator_to_array(CarbonPeriod::create($from, today()), false));
    }

    /**
     * The copilot row.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Counted the tasks completed per day') : __('Counting the tasks completed per day…');
    }
}
