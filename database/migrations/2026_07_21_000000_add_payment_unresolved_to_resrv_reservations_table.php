<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            // True when a cancelled reservation keeps its payment_id only as a reconciliation
            // handle on an unverifiable intent — no money is known to be collected, so reporting
            // must not count it as retained revenue.
            $table->boolean('payment_unresolved')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('payment_unresolved');
        });
    }
};
