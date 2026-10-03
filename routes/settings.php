<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Teams\TeamConnectionController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Controllers\Teams\TeamTokenController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->middleware('throttle:settings')->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Every change to a team, its members, tokens or connections shares one allowance per person: 20 a minute.
    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->middleware('throttle:settings')->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->middleware('throttle:settings')->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->middleware('throttle:settings')->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->middleware('throttle:settings')->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->middleware('throttle:settings')->name('teams.leave');

        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->middleware('throttle:settings')->name('teams.members.update');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->middleware('throttle:settings')->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');

        // The team's "AI clients" page: this person's MCP tokens for the team, bound to it.
        Route::get('settings/teams/{team}/tokens', [TeamTokenController::class, 'index'])->name('teams.tokens.index');
        Route::post('settings/teams/{team}/tokens', [TeamTokenController::class, 'store'])->middleware('throttle:tokens')->name('teams.tokens.store');
        Route::delete('settings/teams/{team}/tokens/{token}', [TeamTokenController::class, 'destroy'])->whereNumber('token')->middleware('throttle:settings')->name('teams.tokens.destroy');

        // The same page's "Connected apps": disconnect an app this person approved with OAuth for the team.
        Route::delete('settings/teams/{team}/connections/{connection}', [TeamConnectionController::class, 'destroy'])->whereNumber('connection')->middleware('throttle:settings')->name('teams.connections.destroy');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
