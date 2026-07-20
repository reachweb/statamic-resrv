<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_reservation_affiliate', function (Blueprint $table) {
            // Rows written before this column existed were attributed via the affiliate cookie
            // or a coupon with no way to tell them apart; defaulting to 'cookie' preserves the
            // behavior they had at the time (both sources could skip payment).
            $table->string('source')->default('cookie')->after('fee');
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservation_affiliate', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
