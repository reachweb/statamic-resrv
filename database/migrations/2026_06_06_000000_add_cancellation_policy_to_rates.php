<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the per-rate cancellation policy (non_refundable | free_cancellation, with a
     * free_cancellation_period in days before check-in; NULL = inherit the global
     * resrv-config.free_cancellation_period) and mirrors the two columns onto reservations
     * so the resolved policy is snapshotted at booking time — later rate or config edits
     * must not change the terms an existing booking was made under.
     */
    public function up(): void
    {
        Schema::table('resrv_rates', function (Blueprint $table) {
            $table->string('cancellation_policy', 20)->nullable()->after('refundable');
            $table->integer('free_cancellation_period')->nullable()->after('cancellation_policy');
        });

        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->string('cancellation_policy', 20)->nullable()->after('rate_id');
            $table->integer('free_cancellation_period')->nullable()->after('cancellation_policy');
        });

        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->string('cancellation_policy', 20)->nullable()->after('rate_id');
            $table->integer('free_cancellation_period')->nullable()->after('cancellation_policy');
        });

        // The legacy `refundable` boolean was stored but never read — carry the admin's
        // intent over to the policy that is actually enforced.
        DB::table('resrv_rates')
            ->where('refundable', false)
            ->update(['cancellation_policy' => 'non_refundable']);

        // meetsBookingLeadTime() previously treated 0 as "unset" (falsy guard); now 0 is
        // meaningful (max_days_before = 0 ⇒ same-day bookings only). Normalize stored
        // zeros to NULL so existing rates keep behaving exactly as they did.
        DB::table('resrv_rates')->where('min_days_before', 0)->update(['min_days_before' => null]);
        DB::table('resrv_rates')->where('max_days_before', 0)->update(['max_days_before' => null]);
    }

    public function down(): void
    {
        Schema::table('resrv_rates', function (Blueprint $table) {
            $table->dropColumn(['cancellation_policy', 'free_cancellation_period']);
        });

        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn(['cancellation_policy', 'free_cancellation_period']);
        });

        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->dropColumn(['cancellation_policy', 'free_cancellation_period']);
        });
    }
};
