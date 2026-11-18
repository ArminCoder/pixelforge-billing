<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What the user asked for.
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Where the image is in its life.
            $table->string('status')->index();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Money, in nanodollars. Estimates are user prices (markup included).
            $table->unsignedBigInteger('estimated_price_min_nanos');
            $table->unsignedBigInteger('estimated_price_max_nanos');
            $table->unsignedBigInteger('cost_nanos')->nullable();
            $table->unsignedBigInteger('price_nanos')->nullable();

            // Exactly what Pixelforge reported, so the cost can be re-checked later.
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();

            // image_url is Pixelforge's link (dies after 24h). image_path is our own copy.
            $table->text('image_url')->nullable();
            $table->string('image_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
