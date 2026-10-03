<?php

namespace App\Http\Controllers;

use AgenticActions\ActionContext;
use AgenticActions\Refusal;
use App\Actions\Tasks\ListTasks;
use App\Actions\Tasks\SummarizeBoard;
use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    /**
     * Show the team's board. The cards and the counts come from the same ListTasks and SummarizeBoard actions the JSON
     * routes, the CLI and the assistant call, run in-process for the signed-in user. Each prop is named after the
     * $touches key that makes it stale, so the client reloads exactly those props after an action succeeds.
     */
    public function __invoke(Request $request, Team $currentTeam): Response
    {
        /** @var User $user */
        $user = $request->user();
        $context = ActionContext::http($user, $currentTeam);
        $filters = $this->filters($request);

        return Inertia::render('board', [
            'filters' => (object) $filters,
            'tasks' => fn (): array => $this->tasks($context, $filters),
            'summary' => fn (): array => SummarizeBoard::run([], $context),
            'projects' => fn (): array => $currentTeam->projects()->orderBy('name')->get(['id', 'name'])->toArray(),
            'members' => fn (): array => $currentTeam->members()->orderBy('name')->get()
                ->map(fn (User $member): array => ['name' => $member->name, 'email' => $member->email])
                ->all(),
            'role' => $user->teamRole($currentTeam)?->label(),
            'can' => [
                'createTask' => $user->hasTeamPermission($currentTeam, TeamPermission::CreateTask),
                'updateTask' => $user->hasTeamPermission($currentTeam, TeamPermission::UpdateTask),
                'deleteTask' => $user->hasTeamPermission($currentTeam, TeamPermission::DeleteTask),
                'createProject' => $user->hasTeamPermission($currentTeam, TeamPermission::CreateProject),
            ],
        ]);
    }

    /**
     * The board's filters from the query string, in ListTasks' own vocabulary.
     *
     * @return array<string, string>
     */
    protected function filters(Request $request): array
    {
        return collect($request->only(['assignee', 'project', 'priority']))
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->all();
    }

    /**
     * The filtered tasks, or every task when a hand-typed filter names nobody or nothing.
     *
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    protected function tasks(ActionContext $context, array $filters): array
    {
        try {
            return ListTasks::run($filters, $context)['tasks'];
        } catch (Refusal|ValidationException) {
            return ListTasks::run([], $context)['tasks'];
        }
    }
}
