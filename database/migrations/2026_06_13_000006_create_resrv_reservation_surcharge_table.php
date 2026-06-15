<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot of the surcharge applied to a reservation at checkout (name + frozen price),
        // mirroring resrv_reservation_extra/option so a later edit to the rule never changes a
        // past booking. Plain integer keys (no FK) to match the other reservation pivots.
        Schema::create('resrv_reservation_surcharge', function (Blueprint $table) {
            $table->integer('reservation_id');
            $table->integer('surcharge_id');
            $table->string('name');
            $table->string('price');

            // One snapshot row per (reservation, surcharge). Without this, two concurrent first-step
            // checkout requests can both clear sync()'s SELECT-then-INSERT window and duplicate the
            // row, which bookingSurchargeTotal() then sums twice — charging the surcharge twice. The
            // composite index also serves reservation-scoped lookups (reservation_id is its prefix).
            $table->unique(['reservation_id', 'surcharge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resrv_reservation_surcharge');
    }
};
