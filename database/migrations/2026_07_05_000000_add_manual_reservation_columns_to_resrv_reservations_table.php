<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            // Default true so every existing and frontend-created reservation keeps
            // decrementing/restoring stock exactly as before this column existed.
            $table->boolean('affects_availability')->default(true);
            // String, not integer: covers both the flat-file user driver and
            // eloquent-driver integer ids.
            $table->string('created_by')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('payment_request_email_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn([
                'affects_availability',
                'created_by',
                'hold_expires_at',
                'payment_request_email_sent_at',
            ]);
        });
    }
};
