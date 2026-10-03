<?php

namespace App\Actions\Demo;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The demo's sample data, Acme and Globex with four people, shared by DatabaseSeeder (fixed emails and slugs, the
 * password "password") and by CreateSandbox (random emails, slugs and passwords, stamped with a sandbox).
 *
 * Plain Eloquent, no factories: Faker is a dev dependency, and a hosted build installs without it.
 *
 *   owner        Acme Owner
 *   member       Acme Member and Globex Member (Marcus Reed)
 *   viewer       Acme Viewer (Vera Lind)
 *   globexOwner  Globex Owner (Gus Novak)
 */
final class SampleBoard
{
    /**
     * The sample teams. A visitor's own teams are counted on top of these.
     *
     * @var list<string>
     */
    public const TEAMS = ['Acme', 'Globex'];

    /**
     * Create one person: verified, with a personal team they own and are switched to.
     */
    public function person(string $name, string $email, string $password, string $personalSlug, ?int $sandboxId = null, ?string $personalTeamName = null): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['email_verified_at' => now(), 'demo_sandbox_id' => $sandboxId])->save();

        $personal = $this->team($personalTeamName ?? "{$name}'s Team", $personalSlug, [[$user, TeamRole::Owner]], $sandboxId, isPersonal: true);

        $user->switchTeam($personal);

        return $user;
    }

    /**
     * Create Acme and Globex with their members and boards, and switch each person to their sample team.
     *
     * @param  array{owner: User, member: User, viewer: User, globexOwner: User}  $people
     * @return array{acme: Team, globex: Team}
     */
    public function teams(array $people, string $acmeSlug, string $globexSlug, ?int $sandboxId = null): array
    {
        ['owner' => $owner, 'member' => $member, 'viewer' => $viewer, 'globexOwner' => $globexOwner] = $people;

        $acme = $this->team('Acme', $acmeSlug, [
            [$owner, TeamRole::Owner],
            [$member, TeamRole::Member],
            [$viewer, TeamRole::Viewer],
        ], $sandboxId);

        $globex = $this->team('Globex', $globexSlug, [
            [$globexOwner, TeamRole::Owner],
            [$member, TeamRole::Member],
        ], $sandboxId);

        foreach ([$owner, $member, $viewer] as $user) {
            $user->switchTeam($acme);
        }

        $globexOwner->switchTeam($globex);

        $this->board($acme, $owner, [
            'Website relaunch' => 'The new marketing site, live before the Q4 campaign.',
            'Mobile app' => 'The first iOS and Android release.',
            'Customer onboarding' => null,
        ], [
            ['Write the launch announcement', 'Website relaunch', TaskStatus::Doing, TaskPriority::High, 2, $owner, 'One page for the blog and a short version for the newsletter.'],
            ['Replace the hero illustration', 'Website relaunch', TaskStatus::Todo, TaskPriority::Normal, 6, $member, null],
            ['Fix broken links on the pricing page', 'Website relaunch', TaskStatus::Todo, TaskPriority::High, -2, $member, 'Three links still point at the old plans.'],
            ['Set up redirects from the old URLs', 'Website relaunch', TaskStatus::Done, TaskPriority::Normal, -5, $owner, null],
            ['Choose an analytics provider', 'Website relaunch', TaskStatus::Done, TaskPriority::Low, -10, null, null],
            ['Submit the iOS build for review', 'Mobile app', TaskStatus::Todo, TaskPriority::High, 9, $owner, 'TestFlight build 42 passed QA.'],
            ['Design the empty states', 'Mobile app', TaskStatus::Doing, TaskPriority::Normal, 4, $member, null],
            ['Crash on login with a long password', 'Mobile app', TaskStatus::Todo, TaskPriority::High, -1, null, 'Reported twice this week. Reproduces on Android 14.'],
            ['Write the welcome email series', 'Customer onboarding', TaskStatus::Todo, TaskPriority::Normal, 14, null, 'Three emails over the first week.'],
            ['Record a two-minute product tour', 'Customer onboarding', TaskStatus::Todo, TaskPriority::Low, null, $member, null],
            ['Interview five new customers', 'Customer onboarding', TaskStatus::Doing, TaskPriority::Normal, 7, $owner, null],
            ['Renew the SSL certificate', null, TaskStatus::Done, TaskPriority::High, -20, $owner, null],
            ['Order new office chairs', null, TaskStatus::Todo, TaskPriority::Low, 21, null, null],
        ]);

        $this->board($globex, $globexOwner, [
            'Warehouse move' => 'Everything out of the Elm Street site by the end of the month.',
            'Supplier audit' => null,
        ], [
            ['Book the moving company', 'Warehouse move', TaskStatus::Done, TaskPriority::High, -7, $globexOwner, null],
            ['Label every rack in aisle C', 'Warehouse move', TaskStatus::Doing, TaskPriority::Normal, 3, $member, null],
            ['Cancel the Elm Street lease', 'Warehouse move', TaskStatus::Todo, TaskPriority::High, -3, $globexOwner, 'Notice period is 30 days.'],
            ['Update the delivery address with carriers', 'Warehouse move', TaskStatus::Todo, TaskPriority::Normal, 10, null, null],
            ['Collect ISO certificates from suppliers', 'Supplier audit', TaskStatus::Doing, TaskPriority::Normal, 5, $member, null],
            ['Score the top ten suppliers', 'Supplier audit', TaskStatus::Todo, TaskPriority::Low, 18, null, null],
            ['Visit the Hamburg plant', 'Supplier audit', TaskStatus::Todo, TaskPriority::Normal, 25, $globexOwner, null],
        ]);

        return ['acme' => $acme, 'globex' => $globex];
    }

    /**
     * Create a team with an explicit slug and add its members with the kit's roles.
     *
     * @param  list<array{0: User, 1: TeamRole}>  $members
     */
    private function team(string $name, string $slug, array $members, ?int $sandboxId, bool $isPersonal = false): Team
    {
        $team = Team::create(['name' => $name, 'slug' => $slug, 'is_personal' => $isPersonal, 'demo_sandbox_id' => $sandboxId]);

        foreach ($members as [$user, $role]) {
            $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        }

        return $team;
    }

    /**
     * Fill a team's projects and tasks. A task row is [title, project, status, priority, due in days, assignee,
     * description]. Due dates count from today. The tasks were added three days apart, the last one today, and a done
     * task was completed two days after it was added, so questions over time have weeks to count.
     *
     * @param  array<string, string|null>  $projects
     * @param  list<array{0: string, 1: string|null, 2: TaskStatus, 3: TaskPriority, 4: int|null, 5: User|null, 6: string|null}>  $tasks
     */
    private function board(Team $team, User $creator, array $projects, array $tasks): void
    {
        $projectIds = collect($projects)
            ->map(fn (?string $description, string $name): int => $team->projects()->create(['name' => $name, 'description' => $description])->id);

        $now = CarbonImmutable::now();

        foreach ($tasks as $index => [$title, $project, $status, $priority, $dueInDays, $assignee, $description]) {
            $addedAt = $now->subDays(3 * (count($tasks) - 1 - $index));

            $team->tasks()->create([
                'title' => $title,
                'description' => $description,
                'project_id' => $project === null ? null : $projectIds[$project],
                'assignee_id' => $assignee?->id,
                'created_by' => $creator->id,
                'status' => $status,
                'priority' => $priority,
                'due_on' => $dueInDays === null ? null : today()->addDays($dueInDays),
                'completed_at' => $status === TaskStatus::Done ? min($addedAt->addDays(2), $now) : null,
            ])->forceFill(['created_at' => $addedAt, 'updated_at' => $addedAt])->saveQuietly();
        }
    }
}
