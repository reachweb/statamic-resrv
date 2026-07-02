<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No foreign keys on purpose: log rows must outlive purged reservations
        // and availability rows (Housekeeping deletes both).
        Schema::create('resrv_availability_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch')->index();
            $table->string('statamic_id');
            $table->unsignedBigInteger('rate_id')->nullable();
            $table->date('date');
            $table->string('action');
            $table->string('field');
            $table->decimal('old_value', 12, 2)->nullable();
            $table->decimal('new_value', 12, 2)->nullable();
            $table->string('reason')->index();
            $table->unsignedBigInteger('reservation_id')->nullable()->index();
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['statamic_id', 'date']);
        });

        Schema::create('resrv_reservation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id')->index();
            $table->string('reference');
            $table->string('status_from')->nullable();
            $table->string('status_to');
            $table->string('reason')->index();
            $table->json('context')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resrv_availability_changes');
        Schema::dropIfExists('resrv_reservation_logs');
    }
};
