<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_reservation_extra', function (Blueprint $table) {
            // The line charged for this extra at booking time. price × quantity only until a
            // dynamic-pricing minimum/maximum binds (Extra::calculatePrice() clamps the line,
            // priceForDates() the unit), so the manual-reservation flow snapshots it explicitly;
            // rows without it (frontend checkout) are linear and fall back to price × quantity.
            $table->string('total')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservation_extra', function (Blueprint $table) {
            $table->dropColumn('total');
        });
    }
};
