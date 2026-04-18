<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->float('payment_surcharge', 8, 2)->default(0)->after('payment');
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('payment_surcharge');
        });
    }
};
