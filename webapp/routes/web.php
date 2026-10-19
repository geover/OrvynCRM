<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AdminOverviewController;
use App\Http\Controllers\Api\V1\ContactAttachmentController;
use App\Http\Controllers\Api\V1\ContactAvatarController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DataObjectItemExtrasController;
use App\Http\Controllers\Api\V1\DataTransferController;
use App\Http\Controllers\Api\V1\NoteTaskAttachmentController;
use App\Http\Controllers\Api\V1\PriceListController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WidgetController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PlatformAdministratorController;
use App\Http\Controllers\PlatformAnnouncementController;
use App\Http\Controllers\PlatformBotProtectionController;
use App\Http\Controllers\PlatformBrandingController;
use App\Http\Controllers\PlatformCompanyOverviewController;
use App\Http\Controllers\PlatformCompanyUsersController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PlatformDashboardController;
use App\Http\Controllers\PlatformDatabaseController;
use App\Http\Controllers\PlatformInactivityController;
use App\Http\Controllers\PlatformIntegrationController;
use App\Http\Controllers\PlatformManualInvoiceController;
use App\Http\Controllers\PlatformMessageController;
use App\Http\Controllers\PlatformObjectLimitController;
use App\Http\Controllers\PlatformPaymentsController;
use App\Http\Controllers\PlatformPlansController;
use App\Http\Controllers\PlatformPricingController;
use App\Http\Controllers\PlatformSeatRequestController;
use App\Http\Controllers\PlatformSecurityController;
use App\Http\Controllers\PlatformServicesController;
use App\Http\Controllers\PlatformSettingsController;
use App\Http\Controllers\PlatformSignupController;
use App\Http\Controllers\PlatformStandingController;
use App\Http\Controllers\PlatformSubscriptionController;
use App\Http\Controllers\PlatformTierChangeController;
use App\Http\Controllers\PlatformUsageController;
use App\Http\Controllers\PlatformWebsiteController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\ThreadAttachmentController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\TwoFactorChallengeController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\UpdatesController;
use App\Http\Controllers\WebAuthController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;

// Public pages: hosted forms (+ embed loader) and shared price lists
Route::get('/f/{uuid}.js', [PublicFormController::class, 'embed'])->where('uuid', '[0-9a-fA-F\-]{36}')->middleware(['tenant.public:form', 'throttle:120,1']);
Route::get('/f/{uuid}', [PublicFormController::class, 'page'])->where('uuid', '[0-9a-fA-F\-]{36}')->middleware(['tenant.public:form', 'throttle:120,1']);
Route::get('/p/{token}', [PriceListController::class, 'publicView'])->where('token', '[A-Za-z0-9]{32}')->middleware(['tenant.public:price_list', 'throttle:120,1']);
Route::get('/t/o/{tracker}', [TrackingController::class, 'open'])->middleware(['tenant.public:tracker', 'throttle:600,1']);
// The unsubscribe link in e-mails. The POSTs are also what a mail program sends for one-click unsubscribe, so they cannot carry a CSRF token:
// the message's own unguessable tracking id is the secret.
Route::get('/u/{tracker}', [UnsubscribeController::class, 'show'])->middleware(['tenant.public:tracker', 'throttle:60,1']);
Route::post('/u/{tracker}', [UnsubscribeController::class, 'confirm'])->withoutMiddleware([PreventRequestForgery::class])->middleware(['tenant.public:tracker', 'throttle:60,1']);
Route::post('/u/{tracker}/resubscribe', [UnsubscribeController::class, 'resubscribe'])->withoutMiddleware([PreventRequestForgery::class])->middleware(['tenant.public:tracker', 'throttle:60,1']);

// Two-step verification and the other ways to sign in, for whoever is signed in (company or platform area)
Route::prefix('account/security')->group(function () {
    Route::post('2fa/start', [AccountSecurityController::class, 'start']);
    Route::post('2fa/cancel', [AccountSecurityController::class, 'cancel']);
    Route::post('2fa/confirm', [AccountSecurityController::class, 'confirm'])->middleware('throttle:10,1');
    Route::post('2fa/recovery-codes', [AccountSecurityController::class, 'recoveryCodes'])->middleware('throttle:10,1');
    Route::post('2fa/disable', [AccountSecurityController::class, 'disable'])->middleware('throttle:10,1');
    Route::post('passkeys/options', [PasskeyController::class, 'registerOptions'])->middleware('throttle:20,1');
    Route::post('passkeys', [PasskeyController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('passkeys/{passkey}', [PasskeyController::class, 'destroy'])->middleware('throttle:10,1');
    Route::get('social/{provider}/link', [SocialAuthController::class, 'link'])->where('provider', '[a-z]+')->middleware('throttle:20,1');
    Route::post('social/{provider}/disconnect', [SocialAuthController::class, 'disconnect'])->where('provider', '[a-z]+')->middleware('throttle:10,1');
});

// "Continue with Google / Microsoft / GitHub / single sign-on" (the callback is where the provider sends the browser back to)
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->where('provider', '[a-z]+')->middleware('throttle:30,1');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])->where('provider', '[a-z]+')->middleware('throttle:30,1');

// Company chooser: signed in centrally, company not chosen yet (or switching)
Route::get('/choose-company', [WebAuthController::class, 'chooseForm']);
Route::post('/choose-company', [WebAuthController::class, 'choose']);

// Platform area: all companies (platform administrators only)
Route::middleware('platform')->prefix('platform')->group(function () {
    Route::get('/', [PlatformController::class, 'index']);
    Route::get('manual', fn () => Inertia::render('Platform/Manual'))->name('platform.manual');
    Route::get('security', [AccountSecurityController::class, 'platform']);
    Route::get('database', [PlatformDatabaseController::class, 'central'])->middleware('throttle:120,1');
    Route::get('companies/{tenant}/database', [PlatformDatabaseController::class, 'company'])->middleware('throttle:120,1');
    Route::get('settings/security', [PlatformSecurityController::class, 'show']);
    Route::patch('settings/security', [PlatformSecurityController::class, 'save']);
    Route::patch('settings/security/passkeys', [PlatformSecurityController::class, 'savePasskeys']);
    Route::patch('settings/security/social/{provider}', [PlatformSecurityController::class, 'saveSocial'])->where('provider', '[a-z]+');
    Route::post('companies/{tenant}/invoices', [PlatformManualInvoiceController::class, 'store']);
    Route::patch('companies/{tenant}/invoices/{invoice}/paid', [PlatformManualInvoiceController::class, 'paid']);
    Route::delete('companies/{tenant}/invoices/{invoice}', [PlatformManualInvoiceController::class, 'destroy']);
    Route::get('standing', [PlatformStandingController::class, 'index']);
    Route::post('standing/{tenant}/remind', [PlatformStandingController::class, 'remind'])->middleware('throttle:30,1');
    Route::get('usage', [PlatformUsageController::class, 'index']);
    Route::post('usage/refresh', [PlatformUsageController::class, 'refresh'])->middleware('throttle:6,1');
    Route::post('companies', [PlatformController::class, 'store']);
    Route::patch('companies/{tenant}', [PlatformController::class, 'update']);
    Route::get('companies/{tenant}/tier-preview', [PlatformTierChangeController::class, 'preview']);
    Route::post('companies/{tenant}/open', [PlatformController::class, 'open']);
    Route::post('companies/{tenant}/services/run', [PlatformServicesController::class, 'run']);
    Route::patch('companies/{tenant}/services/objects/{object}', [PlatformServicesController::class, 'updateObject'])->whereNumber('object');

    Route::get('companies/{tenant}', [PlatformCompanyOverviewController::class, 'show']);
    Route::post('companies/{tenant}/collect', [PlatformCompanyOverviewController::class, 'collect']);
    Route::put('companies/{tenant}/object-limits/{object}', [PlatformObjectLimitController::class, 'update'])->whereNumber('object');
    Route::delete('companies/{tenant}/object-limits/{object}', [PlatformObjectLimitController::class, 'destroy'])->whereNumber('object');
    Route::get('companies/{tenant}/favicon', [PlatformCompanyOverviewController::class, 'favicon']);
    Route::get('companies/{tenant}/users', [PlatformCompanyUsersController::class, 'index']);
    Route::post('companies/{tenant}/users', [PlatformCompanyUsersController::class, 'store']);
    Route::post('companies/{tenant}/users/{user}/reset-two-factor', [PlatformCompanyUsersController::class, 'resetTwoFactor'])->whereNumber('user');
    Route::patch('companies/{tenant}/users/{user}', [PlatformCompanyUsersController::class, 'update'])->whereNumber('user');
    Route::post('companies/{tenant}/users/{user}/avatar', [PlatformCompanyUsersController::class, 'uploadAvatar'])->whereNumber('user');
    Route::delete('companies/{tenant}/users/{user}/avatar', [PlatformCompanyUsersController::class, 'removeAvatar'])->whereNumber('user');
    Route::get('companies/{tenant}/users/{user}/avatar', [PlatformCompanyUsersController::class, 'avatar'])->whereNumber('user');
    Route::get('messages', [PlatformMessageController::class, 'index']);
    Route::get('messages/poll', [PlatformMessageController::class, 'poll'])->middleware('throttle:120,1');
    Route::get('attachments/{attachment}', [PlatformMessageController::class, 'attachment']);
    Route::post('messages/{thread}/reply', [PlatformMessageController::class, 'reply']);
    Route::post('messages/{thread}/read', [PlatformMessageController::class, 'read']);
    Route::post('messages/{thread}/archive', [PlatformMessageController::class, 'archive']);
    Route::post('messages/{thread}/unarchive', [PlatformMessageController::class, 'unarchive']);
    Route::delete('messages/{thread}', [PlatformMessageController::class, 'destroy']);

    Route::get('signups', [PlatformSignupController::class, 'index']);
    Route::patch('signups/mode', [PlatformSignupController::class, 'mode']);
    Route::post('signups/{signup}/approve', [PlatformSignupController::class, 'approve'])->whereNumber('signup');
    Route::post('signups/{signup}/reject', [PlatformSignupController::class, 'reject'])->whereNumber('signup');

    Route::get('pricing', [PlatformPricingController::class, 'show']);
    Route::patch('pricing', [PlatformPricingController::class, 'update']);

    Route::get('seat-requests', [PlatformSeatRequestController::class, 'index']);
    Route::post('seat-requests/{seatRequest}/approve', [PlatformSeatRequestController::class, 'approve']);
    Route::post('seat-requests/{seatRequest}/decline', [PlatformSeatRequestController::class, 'decline']);
    Route::post('seat-requests/{seatRequest}/archive', [PlatformSeatRequestController::class, 'archive']);
    Route::post('seat-requests/{seatRequest}/unarchive', [PlatformSeatRequestController::class, 'unarchive']);
    Route::delete('seat-requests/{seatRequest}', [PlatformSeatRequestController::class, 'destroy']);

    Route::get('dashboard', [PlatformDashboardController::class, 'index']);
    Route::post('dashboard/refresh', [PlatformDashboardController::class, 'refresh']);
    Route::get('dashboard/queue', [PlatformDashboardController::class, 'queue'])->middleware('throttle:60,1');

    Route::get('announcements', [PlatformAnnouncementController::class, 'index']);
    Route::post('announcements', [PlatformAnnouncementController::class, 'store']);
    Route::patch('announcements/{announcement}', [PlatformAnnouncementController::class, 'update']);
    Route::post('announcements/{announcement}/publish', [PlatformAnnouncementController::class, 'publish']);
    Route::post('announcements/{announcement}/archive', [PlatformAnnouncementController::class, 'archive']);
    Route::post('announcements/{announcement}/unarchive', [PlatformAnnouncementController::class, 'unarchive']);
    Route::delete('announcements/{announcement}', [PlatformAnnouncementController::class, 'destroy']);

    Route::get('settings', [PlatformSettingsController::class, 'show']);
    Route::get('settings/notifications', [PlatformSettingsController::class, 'notificationsPage']);
    Route::patch('settings/notifications', [PlatformSettingsController::class, 'notifications']);
    Route::get('settings/mail', [PlatformSettingsController::class, 'mailPage']);
    Route::post('companies/{tenant}/subscription/cancel', [PlatformSubscriptionController::class, 'cancel']);
    Route::post('companies/{tenant}/subscription/resume', [PlatformSubscriptionController::class, 'resume']);
    Route::post('companies/{tenant}/subscription/enable', [PlatformSubscriptionController::class, 'enable']);
    Route::post('subscription-requests/{subscriptionRequest}/approve', [PlatformSubscriptionController::class, 'approve']);
    Route::post('subscription-requests/{subscriptionRequest}/decline', [PlatformSubscriptionController::class, 'decline']);
    Route::get('settings/payments', [PlatformPaymentsController::class, 'show']);
    Route::patch('settings/payments', [PlatformPaymentsController::class, 'save']);
    Route::post('settings/payments/test', [PlatformPaymentsController::class, 'test'])->middleware('throttle:10,1');
    Route::post('settings/payments/move-subscribers', [PlatformPaymentsController::class, 'moveSubscribers'])->middleware('throttle:6,1');
    Route::post('settings/payments/prices', [PlatformPaymentsController::class, 'syncPrices'])->middleware('throttle:10,1');
    Route::get('settings/plans', [PlatformPlansController::class, 'show']);
    Route::patch('settings/plans', [PlatformPlansController::class, 'save']);
    Route::post('settings/plans/reset', [PlatformPlansController::class, 'reset'])->middleware('throttle:10,1');
    Route::get('settings/inactive-companies', [PlatformInactivityController::class, 'show']);
    Route::patch('settings/inactive-companies', [PlatformInactivityController::class, 'save']);
    Route::post('settings/inactive-companies/scan', [PlatformInactivityController::class, 'scan'])->middleware('throttle:10,1');
    Route::post('companies/{tenant}/inactive/keep', [PlatformInactivityController::class, 'keep']);
    Route::post('companies/{tenant}/inactive/suspend', [PlatformInactivityController::class, 'suspend']);
    Route::post('companies/{tenant}/inactive/close', [PlatformInactivityController::class, 'close']);
    Route::get('settings/website', [PlatformWebsiteController::class, 'show']);
    Route::patch('settings/website', [PlatformWebsiteController::class, 'save']);
    Route::post('settings/website/rebuild', [PlatformWebsiteController::class, 'rebuild'])->middleware('throttle:6,1');
    Route::get('settings/bot-protection', [PlatformBotProtectionController::class, 'show']);
    Route::patch('settings/bot-protection', [PlatformBotProtectionController::class, 'save']);
    Route::get('settings/sms', [PlatformIntegrationController::class, 'sms']);
    Route::patch('settings/sms', [PlatformIntegrationController::class, 'saveSms']);
    Route::post('settings/sms/test', [PlatformIntegrationController::class, 'testSms'])->middleware('throttle:10,1');
    Route::get('settings/calls', [PlatformIntegrationController::class, 'calls']);
    Route::patch('settings/calls', [PlatformIntegrationController::class, 'saveCalls']);
    Route::patch('settings/calls/offered', [PlatformIntegrationController::class, 'saveOfferedNumbers']);
    Route::post('settings/calls/numbers', [PlatformIntegrationController::class, 'verifyNumber'])->middleware('throttle:10,1');
    Route::get('settings/calls/numbers/status', [PlatformIntegrationController::class, 'numberStatus'])->middleware('throttle:60,1');
    Route::patch('settings/calls/numbers/{sid}', [PlatformIntegrationController::class, 'renameNumber']);
    Route::delete('settings/calls/numbers/{sid}', [PlatformIntegrationController::class, 'removeNumber']);
    Route::patch('settings/mail', [PlatformSettingsController::class, 'mail']);
    Route::post('settings/mail/reset', [PlatformSettingsController::class, 'resetMail']);
    Route::post('settings/mail/test', [PlatformSettingsController::class, 'test'])->middleware('throttle:10,1');
    Route::patch('settings/mail/sendgrid', [PlatformSettingsController::class, 'sendgrid']);
    Route::post('settings/mail/sendgrid/rotate', [PlatformSettingsController::class, 'rotateSendgrid'])->middleware('throttle:10,1');

    Route::get('services', [PlatformServicesController::class, 'index']);
    Route::patch('services', [PlatformServicesController::class, 'update']);
    Route::patch('services/schedule/{job}', [PlatformServicesController::class, 'schedule']);

    Route::get('look', [PlatformBrandingController::class, 'look']);
    Route::patch('look', [PlatformBrandingController::class, 'updateLook']);
    Route::post('look/logo', [PlatformBrandingController::class, 'uploadLookLogo']);
    Route::delete('look/logo', [PlatformBrandingController::class, 'destroyLookLogo']);
    Route::post('look/favicon', [PlatformBrandingController::class, 'uploadLookFavicon']);
    Route::delete('look/favicon', [PlatformBrandingController::class, 'destroyLookFavicon']);
    Route::get('branding', [PlatformBrandingController::class, 'show']);
    Route::patch('branding', [PlatformBrandingController::class, 'update']);
    Route::post('branding/logo', [PlatformBrandingController::class, 'uploadLogo']);
    Route::delete('branding/logo', [PlatformBrandingController::class, 'destroyLogo']);
    Route::post('branding/logo-light', [PlatformBrandingController::class, 'uploadLogoLight']);
    Route::delete('branding/logo-light', [PlatformBrandingController::class, 'destroyLogoLight']);
    Route::patch('branding/logo-style', [PlatformBrandingController::class, 'logoStyle']);
    Route::post('branding/favicon', [PlatformBrandingController::class, 'uploadFavicon']);
    Route::delete('branding/favicon', [PlatformBrandingController::class, 'destroyFavicon']);
    Route::post('branding/background', [PlatformBrandingController::class, 'uploadBackground']);
    Route::delete('branding/background', [PlatformBrandingController::class, 'destroyBackground']);

    Route::get('administrators', [PlatformAdministratorController::class, 'index']);
    Route::post('administrators', [PlatformAdministratorController::class, 'store']);
    Route::patch('administrators/{administrator}', [PlatformAdministratorController::class, 'update']);
    Route::post('administrators/{administrator}/avatar', [PlatformAdministratorController::class, 'uploadAvatar']);
    Route::delete('administrators/{administrator}/avatar', [PlatformAdministratorController::class, 'removeAvatar']);
    Route::get('administrators/{administrator}/avatar', [PlatformAdministratorController::class, 'showAvatar']);
    Route::post('administrators/{administrator}/reset-password', [PlatformAdministratorController::class, 'sendReset']);
    Route::post('administrators/{administrator}/reset-two-factor', [PlatformAdministratorController::class, 'resetTwoFactor']);
    Route::delete('administrators/{administrator}', [PlatformAdministratorController::class, 'destroy']);
});

Route::middleware(['guest', 'signed-out'])->group(function () {
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show']);
    Route::post('/passkeys/login/options', [PasskeyController::class, 'loginOptions'])->middleware('throttle:30,1');
    Route::post('/passkeys/login', [PasskeyController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store']);   // wrong codes are limited per person inside TwoFactor
    Route::get('/login', [WebAuthController::class, 'create'])->name('login');
    Route::post('/login', [WebAuthController::class, 'store']);   // brute-force protection is inside the controller (failures only)
    // Create your own workspace (offered only while the platform has sign-ups open; see Platform > Sign-ups)
    Route::get('/signup', [SignupController::class, 'create'])->name('signup');
    Route::post('/signup', [SignupController::class, 'store'])->middleware('throttle:signup');
    Route::get('/signup/sent', [SignupController::class, 'sent']);
    Route::post('/signup/resend', [SignupController::class, 'resend'])->middleware('throttle:signup');
    Route::get('/signup/{token}', [SignupController::class, 'show'])->where('token', '[A-Za-z0-9]{48}');
    Route::post('/signup/{token}', [SignupController::class, 'complete'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:signup-complete');
});

Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:login')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:login')->name('password.update');
});

// Signing out must work at every stage: also from the company chooser, where the person is signed in but has no company profile yet
Route::post('/logout', [WebAuthController::class, 'destroy']);
// The platform's pictures and video are public and need no session. Without one, the browser fetching the sign-in video in the background
// cannot use up a message that is waiting to be shown ("Password updated") by being the request that comes first.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])->group(function () {
    Route::get('/brand/logo', [PlatformBrandingController::class, 'logo']);
    Route::get('/brand/logo-light', [PlatformBrandingController::class, 'logoLight']);
    Route::get('/brand/background', [PlatformBrandingController::class, 'background']);
    Route::get('/brand/favicon', [PlatformBrandingController::class, 'favicon']);
    Route::get('/brand/platform-logo', [PlatformBrandingController::class, 'lookLogo']);
    Route::get('/brand/platform-favicon', [PlatformBrandingController::class, 'lookFavicon']);        // the sign-in logo: public, platform-owned
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/updates', [UpdatesController::class, 'index'])->name('updates');
    Route::get('/branding/logo', [BrandingController::class, 'logo']);
    Route::get('/branding/favicon', [BrandingController::class, 'favicon']);

    // File downloads. Browser navigations (clicking a link) authenticate with the session cookie here; the JSON
    // API under /api/v1 only accepts cookies for fetch() calls from the app, so links must not point at it.
    Route::prefix('files')->group(function () {
        Route::get('thread-attachments/{attachment}', [ThreadAttachmentController::class, 'show']);
        Route::get('contacts/{contact}/attachments/{attachment}', [ContactAttachmentController::class, 'download']);
        Route::get('contacts/{contact}/export', [ContactController::class, 'export']);
        Route::get('data-objects/{dataObject}/records/{record}/attachments/{attachment}', [DataObjectItemExtrasController::class, 'download']);
        Route::get('transfers/{transfer}', [DataTransferController::class, 'download']);
        Route::get('widgets/{widget}/export', [WidgetController::class, 'export']);
        Route::get('attachments/{attachment}', [NoteTaskAttachmentController::class, 'download']);
        Route::get('users/{user}/avatar', [UserController::class, 'avatar'])->whereNumber('user');
        Route::get('contacts/{contact}/avatar', [ContactAvatarController::class, 'show']);
    });

    // Pages are thin shells; data comes from /api/v1 (same-origin, session-authenticated via Sanctum).
    Route::get('/', fn () => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('/contacts', fn () => Inertia::render('Contacts/Index'))->name('contacts.index');
    Route::get('/contacts/{contact}', fn (int $contact) => Inertia::render('Contacts/Show', ['contactId' => $contact]))->whereNumber('contact')->name('contacts.show');
    Route::get('/objects', fn () => Inertia::render('Objects/Index'))->name('objects.index');
    Route::get('/objects/new', fn () => Inertia::render('Objects/Edit', ['objectId' => null]))->name('objects.create');
    Route::get('/objects/{object}/edit', fn (int $object) => Inertia::render('Objects/Edit', ['objectId' => $object]))->whereNumber('object')->name('objects.edit');
    Route::get('/objects/{object}', fn (int $object) => Inertia::render('Objects/Show', ['objectId' => $object]))->whereNumber('object')->name('objects.show');
    Route::get('/tasks', fn () => Inertia::render('Tasks/Index'))->name('tasks.index');
    Route::get('/schedules', fn () => Inertia::render('Schedules/Index'))->name('schedules.index');
    Route::get('/boards', fn () => Inertia::render('Boards/Index'))->name('boards.index');
    Route::get('/boards/{board}', fn (int $board) => Inertia::render('Boards/Show', ['boardId' => $board]))->whereNumber('board')->name('boards.show');
    Route::get('/user-settings', fn () => Inertia::render('UserSettings'))->name('user-settings');
    Route::get('/security', [AccountSecurityController::class, 'company'])->name('security');
    Route::get('/manual', fn () => Inertia::render('Manual'))->name('manual');
    Route::get('/billing', fn () => Inertia::render('Billing'))->name('billing');

    // ---- Admin settings (administrators only)
    Route::middleware('admin')->group(function () {
        Route::get('/admin', AdminOverviewController::class)->name('admin');
        foreach ([
            'app-details' => 'AppDetails', 'platform-messages' => 'PlatformMessages', 'look-and-feel' => 'LookAndFeel', 'support-emails' => 'SupportEmails', 'email-domains' => 'EmailDomains',
            'custom-tabs' => 'CustomTabs', 'contact-relationships' => 'ContactRelationships', 'users' => 'Users', 'queries' => 'Queries',
            'blast-progress' => 'BlastProgress', 'data-tools' => 'DataTools', 'workflow-boards' => 'WorkflowBoards', 'scheduler' => 'Scheduler',
            'forms' => 'Forms', 'price-lists' => 'PriceLists', 'phone' => 'Phone', 'keap' => 'Keap', 'twilio' => 'Twilio', 'tours' => 'Tours', 'email-templates' => 'EmailTemplates', 'webhooks' => 'Webhooks',
        ] as $slug => $component) {
            Route::get("/admin/$slug", fn () => Inertia::render("Admin/$component"))->name("admin.$slug");
        }
        Route::get('/admin/queries/{id}', fn (string $id) => Inertia::render('Admin/QueryEdit', ['queryId' => $id === 'new' ? null : (int) $id]))->where('id', 'new|[0-9]+');
        Route::get('/admin/forms/{id}', fn (string $id) => Inertia::render('Admin/FormEdit', ['formId' => $id === 'new' ? null : (int) $id]))->where('id', 'new|[0-9]+');
        Route::get('/admin/users/templates/{id}', fn (string $id) => Inertia::render('Admin/TemplateEdit', ['templateId' => $id === 'new' ? null : (int) $id]))->where('id', 'new|[0-9]+');
        Route::get('/automations', fn () => Inertia::render('Automations/Index'))->name('automations.index');
        Route::get('/automations/{id}', fn (string $id) => Inertia::render('Automations/Edit', ['automationId' => $id === 'new' ? null : (int) $id]))->where('id', 'new|[0-9]+');
    });
});
