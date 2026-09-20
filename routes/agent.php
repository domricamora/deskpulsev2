<?php

use App\Http\Controllers\Agent\WebhookController;
use App\Http\Middleware\VerifyAgentSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Desktop agent API — FROZEN
|--------------------------------------------------------------------------
|
| Agents already installed on Windows, macOS and Linux machines call these
| paths. They are not being rebuilt, so the paths, methods and status codes
| here are fixed.
|
| There is deliberately NO /api/v1 prefix. The migration plan §12 and §57
| propose moving the agent there; the standing instruction that the agent
| "should stay the same and point to the same api interface" overrides it. A
| versioned namespace may be added later IN ADDITION, never as a replacement.
|
| These routes are stateless and unauthenticated in Laravel's sense — no
| session, no CSRF, no `auth` middleware. The device HMAC is the whole of the
| authentication, applied by VerifyAgentSignature. /webhooks/auth is the single
| unsigned call, because it is how a device obtains the secret in the first
| place.
|
| See docs/migration/api-contract.md.
|
*/

// Registration — unsigned by necessity.
Route::post('/auth', [WebhookController::class, 'register']);

Route::middleware(VerifyAgentSignature::class)->group(function () {

    /* Bootstrap */
    Route::get('/me', [WebhookController::class, 'me']);
    Route::get('/clients', [WebhookController::class, 'clients']);
    Route::get('/policy', [WebhookController::class, 'policy']);

    /* Tasks */
    Route::get('/tasks', [WebhookController::class, 'tasks']);
    Route::post('/tasks', [WebhookController::class, 'createTask']);
    Route::delete('/tasks/{id}', [WebhookController::class, 'deleteTask'])->whereNumber('id');

    /* Session lifecycle */
    Route::post('/session', [WebhookController::class, 'startSession']);
    Route::patch('/session/{id}', [WebhookController::class, 'stopSession'])->whereNumber('id');
    Route::post('/session/{id}/task', [WebhookController::class, 'setSessionTask'])->whereNumber('id');

    /* Monitoring data */
    Route::post('/session/{id}/activity', [WebhookController::class, 'activity'])->whereNumber('id');
    Route::post('/session/{id}/windows', [WebhookController::class, 'windows'])->whereNumber('id');
    Route::post('/session/{id}/idle', [WebhookController::class, 'idle'])->whereNumber('id');
    Route::post('/session/{id}/screenshot', [WebhookController::class, 'screenshot'])->whereNumber('id');
});

/*
 * Still to come, on this same prefix and the same HMAC scheme:
 *
 *   GET  /webhooks/remote/poll          Phase 16 — remote control
 *   POST /webhooks/remote/{id}/frame    Phase 16
 *   POST /webhooks/remote/{id}/end      Phase 16
 *   POST /webhooks/wise                 Phase 12 — a PAYMENT PROVIDER callback
 *                                       using RSA-SHA256, not device HMAC, and
 *                                       currently 410 Gone while pay_method is
 *                                       bank transfer
 */
