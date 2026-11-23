<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('pixelforge_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->jsonb('reference_images');

            $table->string('status');
            $table->string('pending_reason')->nullable();
            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->unsignedSmallInteger('unreachable_attempts')->default(0);
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('next_attempt_at')->nullable();

            $table->bigInteger('estimate_min_nanos');
            $table->bigInteger('estimate_max_nanos');
            $table->jsonb('estimate_rates');
            $table->jsonb('rates')->nullable();
            $table->jsonb('usage')->nullable();
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charge_nanos')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'status_changed_at']);
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
