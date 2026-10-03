<?php

namespace App\Actions\Teams;

use App\Actions\Demo\SampleBoard;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTeam
{
    /**
     * Create a new team and add the user as owner.
     *
     * A team made by someone in a hosted-demo sandbox joins that sandbox, so it goes when the sandbox does. While the
     * demo is hosted, a sandbox holds at most demo.caps.teams_per_sandbox teams on top of the sample ones.
     *
     * @throws ValidationException when the sandbox is full
     */
    public function handle(User $user, string $name, bool $isPersonal = false): Team
    {
        $this->ensureSandboxHasRoom($user);

        return DB::transaction(function () use ($user, $name, $isPersonal) {
            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
                'demo_sandbox_id' => $user->demo_sandbox_id,
            ]);

            $membership = $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
            ]);

            $user->switchTeam($team);

            return $team;
        });
    }

    /**
     * Refuse a new team once the hosted sandbox has as many as it may. Deleted teams still count: they stay in the
     * database until the sandbox is purged.
     *
     * @throws ValidationException
     */
    private function ensureSandboxHasRoom(User $user): void
    {
        if (! config('demo.hosted') || $user->demo_sandbox_id === null) {
            return;
        }

        $teams = Team::withTrashed()
            ->where('demo_sandbox_id', $user->demo_sandbox_id)
            ->where('is_personal', false)
            ->count();

        if ($teams >= count(SampleBoard::TEAMS) + (int) config('demo.caps.teams_per_sandbox')) {
            throw ValidationException::withMessages([
                'name' => __('This demo already has as many teams as it can hold.'),
            ]);
        }
    }
}
