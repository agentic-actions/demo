<?php

namespace App\Actions\Concerns;

use AgenticActions\ActionContext;
use AgenticActions\Refusal;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TeamPermission;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * What every board action shares: the team from the context, the kit's permission check, and the names a person or
 * a model uses for people and projects, turned into rows of this team only.
 */
trait InteractsWithBoard
{
    /**
     * The team the call runs in. The package has already checked the actor belongs to it.
     */
    protected function team(ActionContext $context): Team
    {
        return $context->tenant(Team::class);
    }

    /**
     * Whether the actor's role in this team carries the permission, by the starter kit's own TeamRole table.
     */
    protected function allows(ActionContext $context, TeamPermission $permission): bool
    {
        return $context->actor instanceof User
            && $context->actor->hasTeamPermission($this->team($context), $permission);
    }

    /**
     * On the hosted demo, refuse a new row once the team holds as many as its cap in demo.caps allows. Called from
     * handle(), so the board, the JSON route, the copilot and MCP clients all hear the same sentence. Not from
     * authorize(): the package also runs that to decide which tools to list, and a full team should hear why, not
     * lose the tool.
     *
     * @throws Refusal when the team is full
     */
    protected function ensureRoomFor(int $rows, string $cap, string $sentence): void
    {
        $max = (int) config("demo.caps.{$cap}");

        if (config('demo.hosted') && $rows >= $max) {
            throw Refusal::make($sentence, replace: ['max' => $max]);
        }
    }

    /**
     * The team member a person named by email or full name. Empty means nobody.
     *
     * @throws Refusal on the field, listing the team's members, when nobody in the team matches
     */
    protected function memberNamed(Team $team, ?string $emailOrName, string $field = 'assignee'): ?User
    {
        if ($emailOrName === null || trim($emailOrName) === '') {
            return null;
        }

        return $team->findMember($emailOrName) ?? throw Refusal::make('No one in this team goes by that name or email.')
            ->on($field)
            ->listing(array_values($team->members()->orderBy('name')->get()->map(fn (User $user): string => "{$user->name} <{$user->email}>")->all()));
    }

    /**
     * The team's project with this name, ignoring case. Empty means no project.
     *
     * @throws Refusal on "project", listing the team's projects, when none matches
     */
    protected function projectNamed(Team $team, ?string $name): ?Project
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return $team->projects()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first()
            ?? throw Refusal::make('This team has no project by that name.')
                ->on('project')
                ->listing(array_values($team->projects()->orderBy('name')->pluck('name')->all()));
    }

    /**
     * The fields a task is written with, shared by create and update. Update leaves every one optional.
     *
     * @return array<string, Type>
     */
    protected function taskFields(JsonSchema $schema, bool $titleRequired): array
    {
        $title = $schema->string()->min(1)->max(120)->description('A short title, such as "Draft the launch post".');

        return [
            'title' => $titleRequired ? $title->required() : $title,
            'description' => $schema->string()->max(2000)->nullable()->description('Longer details. Optional.'),
            'status' => $schema->string()->enum(TaskStatus::values())->nullable()->description('The board column: todo, doing or done. A new task starts in todo.'),
            'priority' => $schema->string()->enum(TaskPriority::values())->nullable()->description('low, normal or high. A new task is normal.'),
            'due_on' => $schema->string()->format('date')->title(__('Due date'))->nullable()->description('The due date as YYYY-MM-DD, or null for none.'),
            'assignee' => $schema->string()->max(255)->nullable()->description('The email or full name of a member of this team, or null for nobody.'),
            'project' => $schema->string()->max(80)->nullable()->description('The name of one of this team\'s projects, or null for none.'),
        ];
    }

    /**
     * Escape LIKE wildcards for a pattern used with ESCAPE '!'. Not a backslash: the package's Read guard also reads
     * each statement the MySQL way, where '\' is an unterminated string, and refuses what it cannot read.
     */
    protected static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * One task as every board action returns it.
     */
    protected static function taskOutput(JsonSchema $schema): Type
    {
        return $schema->object([
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'description' => $schema->string()->nullable()->required(),
            'status' => $schema->string()->enum(TaskStatus::values())->required(),
            'priority' => $schema->string()->enum(TaskPriority::values())->required(),
            'due_on' => $schema->string()->format('date')->nullable()->required(),
            'overdue' => $schema->boolean()->required(),
            'assignee' => $schema->object([
                'name' => $schema->string()->required(),
                'email' => $schema->string()->required(),
            ])->nullable()->required(),
            'project' => $schema->string()->nullable()->required(),
        ]);
    }
}
