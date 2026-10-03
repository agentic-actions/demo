<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * DatabaseSeeder builds the same local demo it always has, now from SampleBoard, so DEMO.md, the README and the
 * CLI's --as=1..4 keep working: four people with the password "password", Acme and Globex at /acme and /globex.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the four people with their ids, roles and the password "password"', function () {
    $acme = Team::where('slug', 'acme')->sole();
    $globex = Team::where('slug', 'globex')->sole();

    $people = [
        1 => ['Olivia Park', 'owner@example.com', TeamRole::Owner, null, 'olivia-parks-team', 'acme'],
        2 => ['Marcus Reed', 'member@example.com', TeamRole::Member, TeamRole::Member, 'marcus-reeds-team', 'acme'],
        3 => ['Vera Lind', 'viewer@example.com', TeamRole::Viewer, null, 'vera-linds-team', 'acme'],
        4 => ['Gus Novak', 'globex@example.com', null, TeamRole::Owner, 'gus-novaks-team', 'globex'],
    ];

    expect(User::count())->toBe(4);

    foreach ($people as $id => [$name, $email, $acmeRole, $globexRole, $personalSlug, $currentSlug]) {
        $user = User::findOrFail($id);

        expect($user->name)->toBe($name)
            ->and($user->email)->toBe($email)
            ->and(Hash::check('password', $user->password))->toBeTrue()
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->demo_sandbox_id)->toBeNull()
            ->and($user->teamRole($acme))->toBe($acmeRole)
            ->and($user->teamRole($globex))->toBe($globexRole)
            ->and($user->personalTeam()?->slug)->toBe($personalSlug)
            ->and($user->currentTeam?->slug)->toBe($currentSlug);
    }
});

it('seeds the two boards', function () {
    $acme = Team::where('slug', 'acme')->sole();
    $globex = Team::where('slug', 'globex')->sole();

    expect($acme->projects()->reorder('id')->pluck('name')->all())->toBe(['Website relaunch', 'Mobile app', 'Customer onboarding'])
        ->and($acme->tasks()->count())->toBe(13)
        ->and($acme->tasks()->where('title', 'Order new office chairs')->sole()->due_on->toDateString())->toBe(today()->addDays(21)->toDateString())
        ->and($globex->projects()->reorder('id')->pluck('name')->all())->toBe(['Warehouse move', 'Supplier audit'])
        ->and($globex->tasks()->count())->toBe(7)
        ->and(Team::whereNotNull('demo_sandbox_id')->count())->toBe(0)
        ->and(Team::count())->toBe(6);
});
