<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            // What the intent in payment_id charges (payment plus surcharge when it was created), so
            // the checkout resumes it only for the same amount. NULL for intents created before
            // this column existed, which are never resumed.
            $table->decimal('payment_intent_amount', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('payment_intent_amount');
        });
    }
};
