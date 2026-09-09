<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the per-rate "booked units per add-on" divisor used when pricing extras and
     * option values. Add-ons normally multiply by the reservation quantity; a rate that
     * sells several booked units to one guest (a single-occupancy cabin booked as 2 berths)
     * sets units_per_addon = 2 so a per-person extra is charged once: the add-on quantity is
     * ceil(quantity / units_per_addon). NULL (or 1) keeps today's behaviour. The cabin price,
     * the stock decrement and the global ignore_quantity_for_prices flag are not affected.
     *
     * The value is resolved live from the rate (withTrashed) at pricing time and is NOT
     * mirrored onto reservations: add-on prices are already snapshotted per reservation on
     * resrv_reservation_extra.price, and validateTotal() only re-prices inside the checkout hold.
     */
    public function up(): void
    {
        Schema::table('resrv_rates', function (Blueprint $table) {
            $table->integer('units_per_addon')->nullable()->after('max_available');
        });
    }

    public function down(): void
    {
        Schema::table('resrv_rates', function (Blueprint $table) {
            $table->dropColumn('units_per_addon');
        });
    }
};
