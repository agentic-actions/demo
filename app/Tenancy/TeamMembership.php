<?php

namespace App\Tenancy;

use AgenticActions\Contracts\ChecksMembership;
use AgenticActions\Effect;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets a user into a team when the starter kit says they belong to it.
 *
 * Membership is the same for every effect: what a role may do inside the team (the kit's TeamPermission) is each
 * action's authorize(). A user who is not a member reads every team action as not found, on every surface.
 */
final class TeamMembership implements ChecksMembership
{
    /**
     * Whether the actor belongs to the team.
     */
    public function __invoke(Authenticatable $actor, Model $tenant, ?Effect $effect): bool
    {
        if (! $actor instanceof User || ! $tenant instanceof Team) {
            return false;
        }

        return $actor->belongsToTeam($tenant);
    }
}
