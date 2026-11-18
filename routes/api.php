<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

// API routes have no session and no CSRF check, which is what a webhook
// needs. The signature check inside the controller is the protection.
// Full URL: /api/webhooks/pixelforge

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
