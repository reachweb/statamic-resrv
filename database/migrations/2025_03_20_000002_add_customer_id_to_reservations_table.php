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
            $table->foreignId('customer_id')->nullable()->after('payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });
    }
};
