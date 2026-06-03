<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hot availability/capacity checks filter reservations by rate_id and an overlapping
     * date range (date_start < ? AND date_end > ?) — see AvailabilityRepository::validateMaxAvailableForDateRange,
     * getExhaustedDatesForRates and HandlesAvailabilityQueries::validateMaxAvailableForDemand. Only
     * single-column indexes existed, so these scans couldn't use rate_id + date together. A composite
     * index on (rate_id, date_start, date_end) lets the b-tree seek on rate_id, range-scan date_start
     * and filter date_end in-index. status is intentionally left out: it's queried as NOT IN(terminal),
     * which a b-tree can't seek, so it stays a cheap post-filter.
     */
    public function up(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->index(['rate_id', 'date_start', 'date_end'], 'resrv_res_rate_overlap_idx');
        });

        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->index(['rate_id', 'date_start', 'date_end'], 'resrv_child_res_rate_overlap_idx');
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropIndex('resrv_res_rate_overlap_idx');
        });

        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->dropIndex('resrv_child_res_rate_overlap_idx');
        });
    }
};
