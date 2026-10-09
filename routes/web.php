<?php

use App\Http\Controllers\Admin\AllocationListController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DeviceProfileController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\LlmRatingController;
use App\Http\Controllers\Admin\ProtocolActivationController;
use App\Http\Controllers\Admin\ProtocolFreezeController;
use App\Http\Controllers\Admin\ProtocolVersionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ResourceArticleController;
use App\Http\Controllers\Admin\RubricCriterionController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataFileController;
use App\Http\Controllers\ExperimentSessionController;
use App\Http\Controllers\Kiosk\KioskAssistantController;
use App\Http\Controllers\Kiosk\KioskAttemptController;
use App\Http\Controllers\Kiosk\KioskController;
use App\Http\Controllers\Kiosk\KioskEventController;
use App\Http\Controllers\Kiosk\KioskHeartbeatController;
use App\Http\Controllers\Kiosk\KioskResourceController;
use App\Http\Controllers\Kiosk\KioskStepController;
use App\Http\Controllers\KioskLinkController;
use App\Http\Controllers\ParticipantContactController;
use App\Http\Controllers\ParticipantController;
use App\Http\Controllers\ParticipantExclusionController;
use App\Http\Controllers\ParticipantRandomizationController;
use App\Http\Controllers\QualityReviewController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\SessionControlController;
use App\Http\Controllers\SessionDeviceController;
use App\Http\Controllers\SessionMonitorController;
use App\Http\Controllers\SessionNoteController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

/*
|--------------------------------------------------------------------------
| Participant kiosk
|--------------------------------------------------------------------------
|
| No user account: the tester issues a time-limited link per session. All
| JSON endpoints live under /kiosk/{token}/api so they share the same guard.
|
*/

Route::prefix('kiosk/{token}')->middleware('kiosk')->name('kiosk.')->group(function () {
    Route::get('/', [KioskController::class, 'show'])->name('show');

    Route::prefix('api')->name('api.')->group(function () {
        Route::get('state', [KioskController::class, 'state'])->name('state');
        Route::post('heartbeat', KioskHeartbeatController::class)->name('heartbeat');
        Route::post('events', KioskEventController::class)->middleware('throttle:kiosk-events')->name('events');
        Route::post('steps/{step}/start', [KioskStepController::class, 'start'])->name('steps.start');
        Route::post('steps/{step}/complete', [KioskStepController::class, 'complete'])->name('steps.complete');
        Route::patch('attempts/{attempt}/phase', [KioskAttemptController::class, 'phase'])->name('attempts.phase');
        Route::post('attempts/{attempt}/submit', [KioskAttemptController::class, 'submit'])->name('attempts.submit');
        Route::post('attempts/{attempt}/assistant', KioskAssistantController::class)->middleware('throttle:kiosk-assistant')->name('attempts.assistant');
        Route::get('resources', KioskResourceController::class)->name('resources');
    });
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    /*
    |----------------------------------------------------------------------
    | Tester workflows (admins have access as well)
    |----------------------------------------------------------------------
    */

    Route::middleware('role:admin,tester')->group(function () {
        Route::resource('participants', ParticipantController::class)->except('destroy');
        Route::post('participants/{participant}/randomization', [ParticipantRandomizationController::class, 'store'])->name('participants.randomization.store');
        Route::post('participants/{participant}/exclusion', [ParticipantExclusionController::class, 'store'])->name('participants.exclusion.store');
        Route::post('participants/{participant}/sessions', [ExperimentSessionController::class, 'store'])->name('participants.sessions.store');

        Route::get('sessions/{session}', [ExperimentSessionController::class, 'show'])->name('sessions.show');
        Route::patch('sessions/{session}', [ExperimentSessionController::class, 'update'])->name('sessions.update');
        Route::get('sessions/{session}/monitor', SessionMonitorController::class)->name('sessions.monitor');
        Route::post('sessions/{session}/kiosk-link', [KioskLinkController::class, 'store'])->name('sessions.kiosk-link.store');
        Route::delete('sessions/{session}/kiosk-link', [KioskLinkController::class, 'destroy'])->name('sessions.kiosk-link.destroy');
        Route::post('sessions/{session}/pause', [SessionControlController::class, 'pause'])->name('sessions.pause');
        Route::post('sessions/{session}/resume', [SessionControlController::class, 'resume'])->name('sessions.resume');
        Route::post('sessions/{session}/abort', [SessionControlController::class, 'abort'])->name('sessions.abort');
        Route::post('sessions/{session}/notes', [SessionNoteController::class, 'store'])->name('sessions.notes.store');
        Route::post('sessions/{session}/devices', [SessionDeviceController::class, 'store'])->name('sessions.devices.store');
        Route::put('sessions/{session}/devices/{device}', [SessionDeviceController::class, 'update'])->name('sessions.devices.update')->scopeBindings();
        Route::delete('sessions/{session}/devices/{device}', [SessionDeviceController::class, 'destroy'])->name('sessions.devices.destroy')->scopeBindings();
        Route::put('sessions/{session}/quality-review', [QualityReviewController::class, 'update'])->name('sessions.quality-review.update');
        Route::post('sessions/{session}/files', [DataFileController::class, 'store'])->name('sessions.files.store');
        Route::get('sessions/{session}/files/{file}', [DataFileController::class, 'show'])->name('sessions.files.show')->scopeBindings();
        Route::delete('sessions/{session}/files/{file}', [DataFileController::class, 'destroy'])->name('sessions.files.destroy')->scopeBindings();

        Route::get('ratings', [RatingController::class, 'index'])->name('ratings.index');
        Route::get('ratings/{attempt}', [RatingController::class, 'edit'])->name('ratings.edit');
        Route::put('ratings/{attempt}', [RatingController::class, 'update'])->name('ratings.update');
    });

    /*
    |----------------------------------------------------------------------
    | Administration
    |----------------------------------------------------------------------
    */

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);

        Route::resource('protocols', ProtocolVersionController::class)->except(['show', 'destroy']);
        Route::post('protocols/{protocol}/activation', [ProtocolActivationController::class, 'store'])->name('protocols.activation.store');
        Route::post('protocols/{protocol}/freeze', [ProtocolFreezeController::class, 'store'])->name('protocols.freeze.store');
        Route::resource('protocols.tasks', TaskController::class)->except('show')->scoped();
        Route::resource('protocols.resources', ResourceArticleController::class)->except('show')->scoped();
        Route::get('protocols/{protocol}/rubric', [RubricCriterionController::class, 'index'])->name('protocols.rubric.index');
        Route::put('protocols/{protocol}/rubric', [RubricCriterionController::class, 'update'])->name('protocols.rubric.update');
        Route::get('protocols/{protocol}/allocation', [AllocationListController::class, 'show'])->name('protocols.allocation.show');
        Route::post('protocols/{protocol}/allocation', [AllocationListController::class, 'store'])->name('protocols.allocation.store');

        Route::resource('devices', DeviceProfileController::class)->except(['show', 'destroy'])->parameters(['devices' => 'deviceProfile']);

        Route::get('reports/progress', [ReportController::class, 'progress'])->name('reports.progress');
        Route::get('reports/manipulation', [ReportController::class, 'manipulation'])->name('reports.manipulation');
        Route::get('reports/pilot', [ReportController::class, 'pilot'])->name('reports.pilot');
        Route::get('reports/reliability', [ReportController::class, 'reliability'])->name('reports.reliability');
        Route::post('reports/reliability/llm-ratings', [LlmRatingController::class, 'store'])->name('reports.llm-ratings.store');

        Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
        Route::get('exports/{protocol}/{dataset}', [ExportController::class, 'show'])->name('exports.show');

        Route::delete('participants/{participant}/contact', [ParticipantContactController::class, 'destroy'])->name('participants.contact.destroy');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });
});
