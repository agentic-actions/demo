<?php

use AgenticActions\Facades\Actions;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// The hosted demo (404 unless DEMO_HOSTED): a sandbox per visitor, the signed way back into it, and "View as".
Route::post('demo', [DemoController::class, 'store'])->middleware('throttle:demo-start')->name('demo.store');
Route::get('demo/resume/{sandbox}', [DemoController::class, 'resume'])->middleware(['signed', 'throttle:demo-session'])->whereNumber('sandbox')->name('demo.resume');
Route::post('demo/as/{persona}', [DemoController::class, 'viewAs'])->middleware(['auth', 'throttle:demo-session'])->whereNumber('persona')->name('demo.as');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('board', BoardController::class)->name('board');

        // The board assistant: one streamed turn, and the words of this user's conversation in this team.
        Route::post('assistant', [AssistantController::class, 'stream'])->middleware('throttle:assistant')->name('assistant');
        Route::get('assistant/transcript', [AssistantController::class, 'transcript'])->middleware('throttle:transcript')->name('assistant.transcript');

        // POST /{current_team}/actions/{action}, one route per web-exposed action, named actions.{action}; the change
        // feed and every other call keep separate allowances (AppServiceProvider::configureRateLimits).
        Route::middleware('throttle:actions')->group(fn () => Actions::routes(tenant: true));
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
