<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every webhook event we accepted. The unique event_id is how we notice
 * that Pixelforge sent the same event twice.
 */
class WebhookEvent extends Model
{
    protected $guarded = [];
}
