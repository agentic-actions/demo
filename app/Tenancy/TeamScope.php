<?php

namespace App\Tenancy;

use AgenticActions\Contracts\ScopesToTenant;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Scopes ActionContext::find() to the current team. Projects and tasks carry team_id; any other model throws, so a
 * lookup is never left unscoped by accident.
 */
final class TeamScope implements ScopesToTenant
{
    /**
     * Scope a query to the team.
     */
    public function __invoke(Builder $query, Model $tenant): Builder
    {
        $model = $query->getModel();

        if ($model instanceof Task || $model instanceof Project) {
            return $query->where($model->qualifyColumn('team_id'), $tenant->getKey());
        }

        throw new LogicException('TeamScope cannot scope '.$model::class.'.');
    }
}
