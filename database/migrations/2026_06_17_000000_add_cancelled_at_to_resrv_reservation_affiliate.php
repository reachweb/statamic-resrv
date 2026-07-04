<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_reservation_affiliate', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('fee');
            // The pivot never had an index on reservation_id, so every per-reservation
            // UPDATE in the backfill below (and every affiliate() relation load) would
            // scan the whole table. Index first so the backfill is linear, not quadratic.
            $table->index('reservation_id');
        });

        // Commissions of reservations refunded before this column existed were voided by that
        // refund (the money went back to the customer), but a NULL cancelled_at would now report
        // them as active and payable. Stamp them with the reservation's updated_at — the closest
        // surviving record of when the refund happened. Chunked per-reservation updates keep the
        // backfill driver-agnostic (no cross-database UPDATE JOIN syntax), and the whereIn
        // restricts the loop to reservations that actually carry an attribution.
        DB::table('resrv_reservations')
            ->where('status', 'refunded')
            ->whereIn('id', DB::table('resrv_reservation_affiliate')->select('reservation_id'))
            ->select(['id', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($reservations) {
                foreach ($reservations as $reservation) {
                    DB::table('resrv_reservation_affiliate')
                        ->where('reservation_id', $reservation->id)
                        ->whereNull('cancelled_at')
                        ->update(['cancelled_at' => $reservation->updated_at]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('resrv_reservation_affiliate', function (Blueprint $table) {
            $table->dropIndex(['reservation_id']);
            $table->dropColumn('cancelled_at');
        });
    }
};
