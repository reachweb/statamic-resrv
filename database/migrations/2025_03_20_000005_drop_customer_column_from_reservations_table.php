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
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('customer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->json('customer')->nullable()->after('payment_id');
        });
    }
};
