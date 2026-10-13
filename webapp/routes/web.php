<?php

use App\Http\Controllers\Api\V1\ContactAttachmentController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DataObjectItemExtrasController;
use App\Http\Controllers\Api\V1\DataTransferController;
use App\Http\Controllers\Api\V1\PriceListController;
use App\Http\Controllers\Api\V1\WidgetController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PlatformAdministratorController;
use App\Http\Controllers\PlatformAnnouncementController;
use App\Http\Controllers\PlatformBotProtectionController;
use App\Http\Controllers\PlatformBrandingController;
use App\Http\Controllers\PlatformCompanyOverviewController;
use App\Http\Controllers\PlatformCompanyUsersController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PlatformDashboardController;
use App\Http\Controllers\PlatformInactivityController;
use App\Http\Controllers\PlatformIntegrationController;
use App\Http\Controllers\PlatformMessageController;
use App\Http\Controllers\PlatformObjectLimitController;
use App\Http\Controllers\PlatformPricingController;
use App\Http\Controllers\PlatformSeatRequestController;
use App\Http\Controllers\PlatformServicesController;
use App\Http\Controllers\PlatformSettingsController;
use App\Http\Controllers\PlatformSignupController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\ThreadAttachmentController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UpdatesController;
use App\Http\Controllers\WebAuthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public pages: hosted forms (+ embed loader) and shared price lists
Route::get('/f/{uuid}.js', [PublicFormController::class, 'embed'])->where('uuid', '[0-9a-fA-F\-]{36}')->middleware(['tenant.public:form', 'throttle:120,1']);
Route::get('/f/{uuid}', [PublicFormController::class, 'page'])->where('uuid', '[0-9a-fA-F\-]{36}')->middleware(['tenant.public:form', 'throttle:120,1']);
Route::get('/p/{token}', [PriceListController::class, 'publicView'])->where('token', '[A-Za-z0-9]{32}')->middleware(['tenant.public:price_list', 'throttle:120,1']);
Route::get('/t/o/{tracker}', [TrackingController::class, 'open'])->middleware(['tenant.public:tracker', 'throttle:600,1']);

// Company chooser: signed in centrally, company not chosen yet (or switching)
Route::get('/choose-company', [WebAuthController::class, 'chooseForm']);
Route::post('/choose-company', [WebAuthController::class, 'choose']);

// Platform area: all companies (platform administrators only)
Route::middleware('platform')->prefix('platform')->group(function () {
    Route::get('/', [PlatformController::class, 'index']);
    Route::post('companies', [PlatformController::class, 'store']);
    Route::patch('companies/{tenant}', [PlatformController::class, 'update']);
    Route::post('companies/{tenant}/open', [PlatformController::class, 'open']);
    Route::post('companies/{tenant}/services/run', [PlatformServicesController::class, 'run']);
    Route::patch('companies/{tenant}/services/objects/{object}', [PlatformServicesController::class, 'updateObject'])->whereNumber('object');

    Route::get('companies/{tenant}', [PlatformCompanyOverviewController::class, 'show']);
    Route::post('companies/{tenant}/collect', [PlatformCompanyOverviewController::class, 'collect']);
    Route::put('companies/{tenant}/object-limits/{object}', [PlatformObjectLimitController::class, 'update'])->whereNumber('object');
    Route::delete('companies/{tenant}/object-limits/{object}', [PlatformObjectLimitController::class, 'destroy'])->whereNumber('object');
    Route::get('companies/{tenant}/users', [PlatformCompanyUsersController::class, 'index']);
    Route::post('companies/{tenant}/users', [PlatformCompanyUsersController::class, 'store']);
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
    Route::get('settings/inactive-companies', [PlatformInactivityController::class, 'show']);
    Route::patch('settings/inactive-companies', [PlatformInactivityController::class, 'save']);
    Route::post('settings/inactive-companies/scan', [PlatformInactivityController::class, 'scan'])->middleware('throttle:10,1');
    Route::post('companies/{tenant}/inactive/keep', [PlatformInactivityController::class, 'keep']);
    Route::post('companies/{tenant}/inactive/suspend', [PlatformInactivityController::class, 'suspend']);
    Route::post('companies/{tenant}/inactive/close', [PlatformInactivityController::class, 'close']);
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
    Route::post('settings/mail/test', [PlatformSettingsController::class, 'test'])->middleware('throttle:10,1');
    Route::patch('settings/mail/sendgrid', [PlatformSettingsController::class, 'sendgrid']);
    Route::post('settings/mail/sendgrid/rotate', [PlatformSettingsController::class, 'rotateSendgrid'])->middleware('throttle:10,1');

    Route::get('services', [PlatformServicesController::class, 'index']);
    Route::patch('services', [PlatformServicesController::class, 'update']);

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
    Route::post('administrators/{administrator}/reset-password', [PlatformAdministratorController::class, 'sendReset']);
    Route::delete('administrators/{administrator}', [PlatformAdministratorController::class, 'destroy']);
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [WebAuthController::class, 'create'])->name('login');
    Route::post('/login', [WebAuthController::class, 'store']);   // brute-force protection is inside the controller (failures only)
    // Create your own workspace (offered only while the platform has sign-ups open; see Platform > Sign-ups)
    Route::get('/signup', [SignupController::class, 'create'])->name('signup');
    Route::post('/signup', [SignupController::class, 'store'])->middleware('throttle:signup');
    Route::get('/signup/sent', [SignupController::class, 'sent']);
    Route::post('/signup/resend', [SignupController::class, 'resend'])->middleware('throttle:signup');
    Route::get('/signup/{token}', [SignupController::class, 'show'])->where('token', '[A-Za-z0-9]{48}');
    Route::post('/signup/{token}', [SignupController::class, 'complete'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:signup-complete');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:login')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:login')->name('password.update');
});

// Signing out must work at every stage: also from the company chooser, where the person is signed in but has no company profile yet
Route::post('/logout', [WebAuthController::class, 'destroy']);
Route::get('/brand/logo', [PlatformBrandingController::class, 'logo']);
Route::get('/brand/logo-light', [PlatformBrandingController::class, 'logoLight']);
Route::get('/brand/background', [PlatformBrandingController::class, 'background']);
Route::get('/brand/favicon', [PlatformBrandingController::class, 'favicon']);        // the sign-in logo: public, platform-owned

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

    // ---- Admin settings (administrators only)
    Route::middleware('admin')->group(function () {
        Route::get('/admin', fn () => Inertia::render('Admin/Index'))->name('admin');
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
