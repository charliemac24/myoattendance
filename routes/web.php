<?php

use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\AttendanceScanController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\NeedsAttentionController;
use App\Http\Controllers\OrganizationTerminologyController;
use App\Http\Controllers\PersonAttendanceController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PersonImportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleResolutionController;
use App\Http\Controllers\SmsAccessRequestController;
use App\Http\Controllers\SmsTemplateController;
use App\Http\Controllers\SubgroupController;
use App\Http\Controllers\SuperAdminBillingController;
use App\Http\Controllers\SuperAdminEntitlementController;
use App\Http\Controllers\SuperAdminOrganizationController;
use App\Http\Controllers\SuperAdminPaymentController;
use App\Http\Controllers\SuperAdminSmsRequestController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\VerificationController;
use App\Services\BaseSubscriptionService;
use App\Services\BuildHomeDashboard;
use App\Support\OrganizationTerminology;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$redirectIfAuthenticated = function (): ?RedirectResponse {
    if (Auth::check()) {
        if (Auth::user()->isSuperAdmin()) {
            return redirect('/super-admin');
        }

        return redirect(Auth::user()->email_verified_at ? '/app' : '/verify-email');
    }

    return null;
};

Route::redirect('/', '/login');
Route::get('/login', function () use ($redirectIfAuthenticated) {
    return $redirectIfAuthenticated() ?? view('auth.login');
})->name('login');
Route::get('/register', function () use ($redirectIfAuthenticated) {
    return $redirectIfAuthenticated() ?? view('auth.register');
});
Route::middleware('auth')->get('/verify-email', function () {
    if (Auth::user()->isSuperAdmin()) {
        return redirect('/super-admin');
    }

    return Auth::user()->email_verified_at ? redirect('/app') : view('auth.verify-email');
})->name('verification.notice');
Route::view('/forgot-password', 'auth.forgot-password');
Route::get('/reset-password/{token}', function (string $token) {
    return view('auth.reset-password', ['token' => $token, 'email' => request('email', '')]);
})->name('password.reset');
Route::middleware(['auth', 'verified', 'manager'])->get('/app', function (BuildHomeDashboard $homeDashboard) {
    $user = Auth::user();
    if ($user->isSuperAdmin()) {
        return redirect('/super-admin');
    }

    $organization = $user->organization;
    $terminology = OrganizationTerminology::forOrganization($organization);
    $localNow = now($organization->timezone);
    $dashboard = $homeDashboard->handle($organization, $localNow);

    return view('dashboard', compact('user', 'terminology', 'dashboard', 'localNow'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/sms/templates', function () {
    $user = Auth::user();

    return view('sms.templates', compact('user'));
})->name('sms.templates');
Route::middleware(['auth', 'verified', 'manager'])->get('/billing', function () {
    $user = Auth::user();

    return view('billing.index', compact('user'));
})->name('billing');
Route::middleware(['auth', 'verified', 'super_admin'])->get('/super-admin', fn () => view('welcome'))->name('super-admin.home');
Route::middleware(['auth', 'verified', 'manager'])->get('/people', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('people.index', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/people/import', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('people.import', compact('user', 'terminology'));
});
// Static /people/* pages come before /people/{person}, which only matches numeric ids.
Route::middleware(['auth', 'verified', 'manager'])->get('/people/create', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);
    $openAddSheet = true;

    return view('people.index', compact('user', 'terminology', 'openAddSheet'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/people/export', [PersonController::class, 'export']);
Route::middleware(['auth', 'verified', 'manager'])->get('/people/{person}/edit', function (int $person) {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);
    $person = $user->organization->persons()->with(['group:id,name', 'subgroup:id,name'])->findOrFail($person);

    return view('people.edit', compact('user', 'terminology', 'person'));
})->whereNumber('person');
Route::middleware(['auth', 'verified', 'manager'])->get('/people/{person}', function (int $person) {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);
    $person = $user->organization->persons()->with(['group:id,name', 'subgroup:id,name'])->findOrFail($person);

    return view('people.show', compact('user', 'terminology', 'person'));
})->whereNumber('person');
Route::middleware(['auth', 'verified', 'manager'])->get('/groups', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('groups.index', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/groups/create', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('groups.create', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/groups/{group}/edit', function (int $group) {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);
    $group = $user->organization->groups()->findOrFail($group);

    return view('groups.edit', compact('user', 'terminology', 'group'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/subgroups', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('subgroups.index', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/subgroups/create', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('subgroups.create', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/subgroups/{subgroup}/edit', function (int $subgroup) {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);
    $subgroup = $user->organization->subgroups()
        ->with(['group' => fn ($query) => $query->withCount('persons')])
        ->withCount('persons')
        ->findOrFail($subgroup);

    return view('subgroups.edit', compact('user', 'terminology', 'subgroup'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/schedules', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('schedules.index', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/schedules/create', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('schedules.create', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/settings/terminology', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('settings.terminology', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/settings/holidays', function () {
    $user = Auth::user();

    return view('settings.holidays', compact('user'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/settings/team', function () {
    $user = Auth::user();

    return view('settings.team', compact('user'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/attendance', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('attendance.index', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/attendance/monthly', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('attendance.monthly', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/attendance/timesheet', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('attendance.timesheet', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/attendance/needs-attention', function () {
    $user = Auth::user();
    $terminology = OrganizationTerminology::forOrganization($user->organization);

    return view('attendance.needs-attention', compact('user', 'terminology'));
});
Route::middleware(['auth', 'verified', 'manager'])->get('/settings/account', function () {
    $user = Auth::user();

    return view('settings.account', compact('user'));
});
Route::middleware(['auth', 'verified'])->get('/scanner', function () {
    $user = Auth::user();
    if (in_array($user->role, ['owner', 'admin'], true) && ! app(BaseSubscriptionService::class)->canUseScanner($user->organization)) {
        return redirect('/billing')->with('subscription_expired', true);
    }

    return view('scanner.index', compact('user'));
});

Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::prefix('api/auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

    Route::middleware('auth')->group(function (): void {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/account', [AuthController::class, 'updateAccount']);
        Route::put('/password', [AuthController::class, 'updatePassword']);
        Route::post('/verification/resend', [VerificationController::class, 'resend'])->middleware('throttle:6,1');
    });
});

Route::middleware(['auth', 'verified', 'manager'])->get('/api/app', function (): array {
    return ['message' => 'Phase 1 authentication is working.'];
});

Route::middleware(['auth', 'verified'])->post('/api/attendance/scan', [AttendanceScanController::class, 'store']);

Route::middleware(['auth', 'verified', 'manager'])->prefix('api')->group(function (): void {
    Route::get('/groups/options', [GroupController::class, 'options']);
    Route::apiResource('groups', GroupController::class)->except(['create', 'edit']);
    Route::get('/subgroups/options', [SubgroupController::class, 'options']);
    Route::apiResource('subgroups', SubgroupController::class)->except(['create', 'edit']);
    Route::apiResource('schedules', ScheduleController::class)->except(['create', 'edit']);
    Route::get('/people/{person}/schedule', [ScheduleResolutionController::class, 'show']);
    Route::get('/people/{person}/attendance', [PersonAttendanceController::class, 'show']);
    Route::get('/people/{person}/attendance/export', [PersonAttendanceController::class, 'export']);
    Route::post('/people/import', [PersonImportController::class, 'store']);
    Route::get('/people/export', [PersonController::class, 'export']);
    Route::post('/people/{person}/archive', [PersonController::class, 'archive']);
    Route::post('/people/{person}/regenerate-qr', [PersonController::class, 'regenerateQr']);
    Route::get('/people/{person}/qr', [PersonController::class, 'qr']);
    Route::apiResource('people', PersonController::class)->except(['create', 'edit', 'destroy'])->where(['person' => '[0-9]+']);
    Route::get('/attendance/report', [AttendanceReportController::class, 'show']);
    Route::get('/attendance/monthly', [AttendanceReportController::class, 'monthly']);
    Route::get('/attendance/monthly/export', [AttendanceReportController::class, 'monthlyExport']);
    Route::get('/attendance/needs-attention', [NeedsAttentionController::class, 'show']);
    Route::get('/attendance/timesheet', [TimesheetController::class, 'show']);
    Route::get('/attendance/timesheet/export', [TimesheetController::class, 'export']);
    Route::get('/attendance/needs-attention/export', [NeedsAttentionController::class, 'export']);
    Route::get('/organization/terminology', [OrganizationTerminologyController::class, 'show']);
    Route::put('/organization/terminology', [OrganizationTerminologyController::class, 'update']);
    Route::post('/organization/terminology/reset', [OrganizationTerminologyController::class, 'reset']);
    Route::put('/organization/industry', [OrganizationTerminologyController::class, 'updateIndustry']);
    Route::apiResource('team', TeamController::class)->except(['show']);
    Route::get('/holidays', [HolidayController::class, 'index']);
    Route::post('/holidays', [HolidayController::class, 'store']);
    Route::post('/holidays/common', [HolidayController::class, 'addCommon']);
    Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday');
    Route::get('/organization/sms/access', [SmsAccessRequestController::class, 'show']);
    Route::post('/organization/sms/access/request', [SmsAccessRequestController::class, 'store']);
    Route::get('/billing', [BillingController::class, 'show']);
    Route::post('/billing/start-choice', [BillingController::class, 'startChoice']);
    Route::post('/billing/sms-estimate', [BillingController::class, 'smsEstimate']);
    Route::post('/billing/payments', [BillingController::class, 'submit']);
    Route::get('/billing/payments/{payment}/proof', [BillingController::class, 'proof']);
    Route::post('/billing/payments/{payment}/corrected-proof', [BillingController::class, 'correctedProof']);
    Route::post('/billing/payments/{payment}/proof-unavailable', [BillingController::class, 'proofUnavailable']);
    Route::get('/organization/sms/templates', [SmsTemplateController::class, 'index']);
    Route::put('/organization/sms/templates/{template}', [SmsTemplateController::class, 'update']);
});

Route::middleware(['auth', 'verified', 'super_admin'])->prefix('api/super-admin')->name('super-admin.')->group(function (): void {
    Route::get('/dashboard', [SuperAdminOrganizationController::class, 'dashboard'])->name('dashboard');
    Route::get('/organizations', [SuperAdminOrganizationController::class, 'index'])->name('organizations.index');
    Route::get('/organizations/{organization}', [SuperAdminOrganizationController::class, 'show'])->name('organizations.show');
    Route::put('/organizations/{organization}/sms-pricing', [SuperAdminOrganizationController::class, 'updateSmsPricing'])->name('organizations.sms-pricing');
    Route::put('/organizations/{organization}/sms-sender-name', [SuperAdminOrganizationController::class, 'updateSmsSenderName'])->name('organizations.sms-sender-name');
    Route::delete('/organizations/{organization}/attendance/current-day', [SuperAdminOrganizationController::class, 'deleteCurrentDayAttendance'])->name('organizations.attendance.current-day.delete');
    Route::get('/payments', [SuperAdminPaymentController::class, 'index'])->name('payments.index');
    Route::get('/billing/payments', [SuperAdminBillingController::class, 'index'])->name('billing.payments.index');
    Route::get('/billing/payments/{payment}', [SuperAdminBillingController::class, 'show'])->name('billing.payments.show');
    Route::post('/billing/payments/{payment}/approve', [SuperAdminBillingController::class, 'approve'])->name('billing.payments.approve');
    Route::post('/billing/payments/{payment}/activate', [SuperAdminBillingController::class, 'approve'])->name('billing.payments.activate');
    Route::post('/billing/payments/{payment}/reject', [SuperAdminBillingController::class, 'reject'])->name('billing.payments.reject');
    Route::post('/billing/payments/{payment}/request-proof', [SuperAdminBillingController::class, 'requestProof'])->name('billing.payments.request-proof');
    Route::get('/billing/payments/{payment}/proof', [SuperAdminBillingController::class, 'proof'])->name('billing.payments.proof');
    Route::get('/billing/payments/{payment}/proof/view', [SuperAdminBillingController::class, 'proofPreview'])->name('billing.payments.proof.view');
    Route::post('/organizations/{organization}/payments', [SuperAdminPaymentController::class, 'store'])->name('payments.store');
    Route::post('/organizations/{organization}/sms/enable', [SuperAdminEntitlementController::class, 'enable'])->name('sms.enable');
    Route::post('/organizations/{organization}/sms/extend', [SuperAdminEntitlementController::class, 'extend'])->name('sms.extend');
    Route::post('/organizations/{organization}/sms/disable', [SuperAdminEntitlementController::class, 'disable'])->name('sms.disable');
    Route::post('/organizations/{organization}/sms/resume', [SuperAdminEntitlementController::class, 'resume'])->name('sms.resume');
    Route::get('/sms-requests', [SuperAdminSmsRequestController::class, 'index'])->name('sms-requests.index');
    Route::get('/sms-requests/{featureRequest}', [SuperAdminSmsRequestController::class, 'show'])->name('sms-requests.show');
    Route::put('/sms-requests/{featureRequest}/pricing', [SuperAdminSmsRequestController::class, 'setPricing'])->name('sms-requests.pricing');
    Route::post('/sms-requests/{featureRequest}/send-quote', [SuperAdminSmsRequestController::class, 'sendQuote'])->name('sms-requests.send-quote');
    Route::post('/sms-requests/{featureRequest}/approve', [SuperAdminSmsRequestController::class, 'approve'])->name('sms-requests.approve');
    Route::post('/sms-requests/{featureRequest}/reject', [SuperAdminSmsRequestController::class, 'reject'])->name('sms-requests.reject');
});

if (app()->environment('local')) {
    Route::get('/dev/emails/verify-email', function () {
        return view('emails.auth.verify-email', [
            'url' => url('/email/verify/1/preview'),
            'greeting' => 'Hi Jordan,',
            'trialEndsAt' => now()->addDays(30)->format('M j, Y'),
            'monthlyPrice' => '₱'.number_format(BaseSubscriptionService::MONTHLY_PRICE_MINOR / 100, 0),
        ]);
    })->name('dev.emails.verify-email');

    Route::get('/dev/emails/reset-password', function () {
        return view('emails.auth.reset-password', [
            'url' => url('/reset-password/preview-token?email=owner%40example.com'),
            'greeting' => 'Hi Jordan,',
            'expireMinutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
        ]);
    })->name('dev.emails.reset-password');
}
