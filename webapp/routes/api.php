<?php

use App\Http\Controllers\Api\V1;
use App\Http\Controllers\PublicFormController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| OrvynCRM API v1
|--------------------------------------------------------------------------
| Replaces the 17k-line rest/V1.php god-controller. One resource, one controller;
| every route name maps to a legacy method in docs/LEGACY_ENDPOINT_MAP.md.
*/

Route::prefix('v1')->group(function () {
    // ---- Public (throttled) ----
    Route::post('auth/token', [V1\AuthController::class, 'token']);   // failures-only throttle lives in the controller
    Route::get('forms/{uuid}', [PublicFormController::class, 'show'])->where('uuid', '[0-9a-fA-F\-]{36}')->middleware(['tenant.public:form', 'throttle:60,1']);
    Route::post('forms/{uuid}/submit', [PublicFormController::class, 'submit'])->where('uuid', '[0-9a-fA-F\-]{36}')->middleware(['tenant.public:form', 'throttle:public-forms']);
    Route::post('hooks/sendgrid', [V1\InboundWebhookController::class, 'sendgrid'])->middleware(['tenant.public:webhook', 'throttle:600,1']);
    Route::post('hooks/sendgrid/platform', [V1\InboundWebhookController::class, 'sendgridPlatform'])->middleware('throttle:600,1');
    Route::get('public/website', V1\PublicWebsiteController::class)->middleware('throttle:60,1');
    Route::post('hooks/stripe', [V1\StripeWebhookController::class, 'handle'])->middleware('throttle:600,1');
    Route::post('hooks/twilio', [V1\InboundWebhookController::class, 'twilio'])->middleware(['tenant.public:webhook', 'throttle:600,1']);

    // ---- Authenticated (Sanctum token OR SPA session) ----
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [V1\AuthController::class, 'me']);
        Route::post('auth/logout', [V1\AuthController::class, 'logout']);
        Route::get('dashboard', V1\DashboardController::class);
        Route::post('dashboard/setup/dismiss', [V1\DashboardController::class, 'dismissSetup']);
        Route::get('search', V1\SearchController::class)->middleware('throttle:120,1');

        // Profile, tokens, notifications
        Route::patch('me', [V1\MeController::class, 'update']);
        Route::get('me/tokens', [V1\MeController::class, 'tokens']);
        Route::post('me/tokens', [V1\MeController::class, 'createToken']);
        Route::delete('me/tokens/{id}', [V1\MeController::class, 'deleteToken']);
        Route::get('notifications', [V1\NotificationController::class, 'index']);
        Route::post('notifications/read/{id?}', [V1\NotificationController::class, 'read']);
        Route::delete('notifications', [V1\NotificationController::class, 'clear']);

        // App settings (admin writes), branding
        Route::get('settings', [V1\SettingsController::class, 'show']);
        Route::put('settings', [V1\SettingsController::class, 'update']);
        Route::post('settings/webhook-token', [V1\SettingsController::class, 'rotateWebhookToken']);
        Route::post('settings/logo', [V1\SettingsController::class, 'logo']);
        Route::delete('settings/logo', [V1\SettingsController::class, 'destroyLogo']);
        Route::post('settings/favicon', [V1\SettingsController::class, 'favicon']);
        Route::get('platform-threads', [V1\PlatformThreadController::class, 'index']);
        Route::get('platform-threads/poll', [V1\PlatformThreadController::class, 'poll'])->middleware('throttle:120,1');
        Route::post('platform-threads', [V1\PlatformThreadController::class, 'store'])->middleware('throttle:10,60');
        Route::get('platform-threads/{thread}', [V1\PlatformThreadController::class, 'show']);
        Route::post('platform-threads/{thread}/messages', [V1\PlatformThreadController::class, 'reply'])->middleware('throttle:60,60');
        Route::post('platform-threads/{thread}/archive', [V1\PlatformThreadController::class, 'archive']);
        Route::post('platform-threads/{thread}/unarchive', [V1\PlatformThreadController::class, 'unarchive']);
        Route::delete('platform-threads/{thread}', [V1\PlatformThreadController::class, 'destroy']);
        Route::get('seats', [V1\SeatController::class, 'show']);
        Route::post('seats/request', [V1\SeatController::class, 'request']);
        Route::get('limits', [V1\LimitController::class, 'show']);
        Route::post('limits/request', [V1\LimitController::class, 'request']);
        Route::post('limits/requests/{seatRequest}/dismiss', [V1\LimitController::class, 'dismiss']);
        Route::delete('settings/favicon', [V1\SettingsController::class, 'destroyFavicon']);
        Route::apiResource('permission-templates', V1\PermissionTemplateController::class)->except('show')->parameters(['permission-templates' => 'permissionTemplate']);
        Route::get('custom-tabs', [V1\CustomTabController::class, 'index']);
        Route::get('custom-tabs/{customTab}', [V1\CustomTabController::class, 'show']);
        Route::put('custom-tabs', [V1\CustomTabController::class, 'sync']);
        Route::get('contact-relationship-types', [V1\ContactRelationshipTypeController::class, 'index']);
        Route::put('contact-relationship-types', [V1\ContactRelationshipTypeController::class, 'sync']);

        // Queries (admin builds, everyone runs) + dashboard widgets
        Route::get('queries/meta', [V1\SavedQueryController::class, 'meta']);
        Route::post('queries/preview', [V1\SavedQueryController::class, 'preview']);
        Route::post('queries/export', [V1\SavedQueryController::class, 'export']);
        Route::post('queries/batch/{channel}', [V1\SavedQueryController::class, 'batch']);
        Route::get('queries/{savedQuery}/run', [V1\SavedQueryController::class, 'run']);
        Route::apiResource('queries', V1\SavedQueryController::class)->except('show')->parameters(['queries' => 'savedQuery']);
        Route::post('widgets/reorder', [V1\WidgetController::class, 'reorder']);
        Route::get('widgets/{widget}/data', [V1\WidgetController::class, 'data']);
        Route::get('widgets/{widget}/export', [V1\WidgetController::class, 'export']);
        Route::post('widgets/{widget}/batch/{channel}', [V1\WidgetController::class, 'batch']);
        Route::apiResource('widgets', V1\WidgetController::class)->except('show');

        // Boards, schedules, calendar
        Route::get('boards', [V1\WorkflowBoardController::class, 'index']);
        Route::get('boards/{board}', [V1\WorkflowBoardController::class, 'show']);
        Route::patch('boards/{board}/cards/{record}', [V1\WorkflowBoardController::class, 'move']);
        Route::post('boards', [V1\WorkflowBoardController::class, 'store']);
        Route::put('boards/{board}', [V1\WorkflowBoardController::class, 'update']);
        Route::delete('boards/{board}', [V1\WorkflowBoardController::class, 'destroy']);
        Route::get('calendar', [V1\ScheduleController::class, 'calendar']);
        Route::apiResource('schedules', V1\ScheduleController::class)->except('show');

        // Forms, import/export, price lists, tours
        Route::get('forms/{form}/submissions', [V1\FormController::class, 'submissions']);
        Route::apiResource('forms', V1\FormController::class)->whereNumber('form');
        Route::post('transfers/preview', [V1\DataTransferController::class, 'preview']);
        Route::post('transfers/rows', [V1\DataTransferController::class, 'rows']);
        Route::get('transfers', [V1\DataTransferController::class, 'index']);
        Route::post('transfers', [V1\DataTransferController::class, 'store']);
        Route::get('transfers/{transfer}/download', [V1\DataTransferController::class, 'download']);
        Route::post('price-lists/{priceList}/lock', [V1\PriceListController::class, 'lock']);
        Route::post('price-lists/{priceList}/duplicate', [V1\PriceListController::class, 'duplicate']);
        Route::apiResource('price-lists', V1\PriceListController::class)->parameters(['price-lists' => 'priceList']);
        Route::post('tours/{tour}/notify', [V1\TourController::class, 'notify']);
        Route::apiResource('tours', V1\TourController::class);
        Route::get('messages/blast-users', fn () => response()->json(['data' => User::listed()->orderBy('name')->get(['id', 'name'])]));

        // Contacts
        Route::get('contacts/duplicates', [V1\ContactController::class, 'duplicates']);
        Route::get('contacts/{contact}/export', [V1\ContactController::class, 'export']);
        Route::post('contacts/bulk', [V1\ContactController::class, 'bulk']);
        Route::apiResource('contacts', V1\ContactController::class);
        Route::post('contacts/{contact}/merge', [V1\ContactController::class, 'merge']);
        Route::get('contacts/{contact}/timeline', V1\TimelineController::class);
        Route::apiResource('contacts.notes', V1\ContactNoteController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('note-tags', [V1\ContactNoteController::class, 'tags']);
        Route::get('contacts/{contact}/messages', [V1\MessageController::class, 'history']);
        Route::get('contacts/{contact}/data-objects', V1\ContactDataObjectController::class);
        Route::get('contacts/{contact}/relationships', [V1\ContactRelationshipController::class, 'index']);
        Route::post('contacts/{contact}/relationships', [V1\ContactRelationshipController::class, 'store']);
        Route::delete('contacts/{contact}/relationships/{relationship}', [V1\ContactRelationshipController::class, 'destroy']);
        Route::post('contacts/{contact}/avatar', [V1\ContactAvatarController::class, 'store']);
        Route::delete('contacts/{contact}/avatar', [V1\ContactAvatarController::class, 'destroy']);
        Route::get('contacts/{contact}/attachments', [V1\ContactAttachmentController::class, 'index']);
        Route::post('contacts/{contact}/attachments', [V1\ContactAttachmentController::class, 'store']);
        Route::get('contacts/{contact}/attachments/{attachment}', [V1\ContactAttachmentController::class, 'download']);
        Route::delete('contacts/{contact}/attachments/{attachment}', [V1\ContactAttachmentController::class, 'destroy']);
        Route::post('contacts/{contact}/email', [V1\MessageController::class, 'email']);
        Route::post('contacts/{contact}/sms', [V1\MessageController::class, 'sms']);

        // Tasks
        Route::apiResource('tasks', V1\TaskController::class)->except('show');
        Route::post('notes/{note}/attachments', [V1\NoteTaskAttachmentController::class, 'forNote']);
        Route::post('tasks/{task}/attachments', [V1\NoteTaskAttachmentController::class, 'forTask']);
        Route::get('attachments/{attachment}', [V1\NoteTaskAttachmentController::class, 'download']);
        Route::delete('attachments/{attachment}', [V1\NoteTaskAttachmentController::class, 'destroy']);

        // Data objects (custom objects) + records
        Route::get('relationships', [V1\RelationshipController::class, 'index']);
        Route::post('relationships', [V1\RelationshipController::class, 'store']);
        Route::delete('relationships/{relationship}', [V1\RelationshipController::class, 'destroy']);
        Route::apiResource('data-objects', V1\DataObjectController::class)->parameters(['data-objects' => 'dataObject']);
        Route::prefix('data-objects/{dataObject}/records')->group(function () {
            Route::get('/', [V1\DataObjectRecordController::class, 'index']);
            Route::post('/', [V1\DataObjectRecordController::class, 'store']);
            Route::get('{record}', [V1\DataObjectRecordController::class, 'show']);
            Route::patch('{record}', [V1\DataObjectRecordController::class, 'update']);
            Route::delete('{record}', [V1\DataObjectRecordController::class, 'destroy']);
            Route::post('{record}/contacts', [V1\DataObjectRecordController::class, 'attach']);
            Route::get('{record}/history', [V1\DataObjectItemExtrasController::class, 'history']);
            Route::get('{record}/attachments', [V1\DataObjectItemExtrasController::class, 'attachments']);
            Route::post('{record}/attachments', [V1\DataObjectItemExtrasController::class, 'upload']);
            Route::get('{record}/attachments/{attachment}', [V1\DataObjectItemExtrasController::class, 'download']);
            Route::delete('{record}/attachments/{attachment}', [V1\DataObjectItemExtrasController::class, 'destroyAttachment']);
            Route::delete('{record}/contacts/{contactId}', [V1\DataObjectRecordController::class, 'detach']);
        });

        // Automation + messaging
        Route::get('automations/{automation}/runs', [V1\AutomationController::class, 'runs']);
        Route::apiResource('automations', V1\AutomationController::class);
        Route::get('webhooks/{webhook}/deliveries', [V1\WebhookEndpointController::class, 'deliveries']);
        Route::apiResource('webhooks', V1\WebhookEndpointController::class)->except('show');
        Route::post('automations/{automation}/run', [V1\AutomationController::class, 'run']);
        Route::post('support', V1\SupportController::class)->middleware('throttle:10,1');
        Route::get('messages/readiness', [V1\MessageController::class, 'readiness']);
        Route::get('messages/queue', [V1\MessageController::class, 'queue']);
        Route::get('messages/blast', [V1\MessageController::class, 'blast']);
        Route::get('blasts', [V1\BlastController::class, 'index']);
        Route::get('blasts/{blast}', [V1\BlastController::class, 'show'])->whereNumber('blast');
        Route::get('messages/email/{email}', [V1\MessageController::class, 'showEmail']);
        Route::post('messages/email/{email}/resend', [V1\MessageController::class, 'resend']);
        Route::delete('suppressions/{email}', [V1\MessageController::class, 'unsuppress']);
        Route::post('uploads', [V1\UploadController::class, 'store']);
        Route::get('email-templates/merge-fields', [V1\EmailTemplateController::class, 'mergeFields']);
        Route::post('email-templates/{emailTemplate}/duplicate', [V1\EmailTemplateController::class, 'duplicate']);
        Route::apiResource('email-templates', V1\EmailTemplateController::class)->parameters(['email-templates' => 'emailTemplate']);

        // Admin
        Route::get('phone', [V1\UserController::class, 'phone']);
        Route::get('caller-ids', [V1\CallerIdController::class, 'index']);
        Route::post('caller-ids/verify', [V1\CallerIdController::class, 'verify'])->middleware('throttle:10,1');
        Route::get('caller-ids/status', [V1\CallerIdController::class, 'status'])->middleware('throttle:60,1');
        Route::patch('caller-ids/{sid}', [V1\CallerIdController::class, 'update']);
        Route::delete('caller-ids/{sid}', [V1\CallerIdController::class, 'destroy']);
        Route::get('billing', [V1\BillingController::class, 'show']);
        Route::put('billing', [V1\BillingController::class, 'save']);
        Route::post('billing/plan', [V1\BillingController::class, 'changePlan'])->middleware('throttle:20,1');
        Route::post('billing/checkout', [V1\BillingController::class, 'checkout'])->middleware('throttle:20,1');
        Route::post('billing/cancel', [V1\BillingController::class, 'cancel'])->middleware('throttle:10,1');
        Route::post('billing/resume', [V1\BillingController::class, 'resume'])->middleware('throttle:10,1');
        Route::post('billing/resubscribe', [V1\BillingController::class, 'resubscribe'])->middleware('throttle:10,1');
        Route::post('billing/text-request', [V1\BillingController::class, 'requestTexts'])->middleware('throttle:10,1');
        Route::post('billing/portal', [V1\BillingController::class, 'portal'])->middleware('throttle:20,1');
        Route::get('sms-numbers', [V1\SmsNumbersController::class, 'options']);
        Route::get('integrations/sms-numbers', [V1\SmsNumbersController::class, 'show']);
        Route::put('integrations/sms-numbers', [V1\SmsNumbersController::class, 'save']);
        Route::get('integrations/twilio', [V1\TwilioIntegrationController::class, 'show']);
        Route::put('integrations/twilio', [V1\TwilioIntegrationController::class, 'save']);
        Route::delete('integrations/twilio', [V1\TwilioIntegrationController::class, 'destroy']);
        Route::post('integrations/twilio/test', [V1\TwilioIntegrationController::class, 'test'])->middleware('throttle:10,1');
        Route::get('integrations/keap', [V1\KeapIntegrationController::class, 'show']);
        Route::put('integrations/keap', [V1\KeapIntegrationController::class, 'save']);
        Route::delete('integrations/keap', [V1\KeapIntegrationController::class, 'destroy']);
        Route::post('integrations/keap/test', [V1\KeapIntegrationController::class, 'test'])->middleware('throttle:10,1');
        Route::post('integrations/keap/sync', [V1\KeapIntegrationController::class, 'sync'])->middleware('throttle:10,1');
        Route::post('users/{user}/avatar', [V1\UserController::class, 'uploadAvatar']);
        Route::delete('users/{user}/avatar', [V1\UserController::class, 'removeAvatar']);
        Route::post('users/{user}/reset-password', [V1\UserController::class, 'sendReset']);
        Route::post('users/{user}/reset-two-factor', [V1\UserController::class, 'resetTwoFactor']);
        Route::apiResource('users', V1\UserController::class)->except('show');
    });
});
