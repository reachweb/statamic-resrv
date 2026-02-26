<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->dropUnique(['statamic_id', 'date', 'property']);
            $table->dropIndex(['statamic_id', 'date', 'property', 'available']);
        });

        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->dropColumn('property');
        });

        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->unsignedBigInteger('rate_id')->nullable(false)->change();
            $table->unique(['statamic_id', 'date', 'rate_id']);
            $table->foreign('rate_id')->references('id')->on('resrv_rates');
        });

        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('property');
        });

        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->dropColumn('property');
        });

        Schema::table('resrv_fixed_pricing', function (Blueprint $table) {
            $table->dropUnique(['statamic_id', 'days']);
            $table->unique(['statamic_id', 'days', 'rate_id']);
        });

        Schema::dropIfExists('resrv_advanced_availabilities');
    }

    public function down(): void
    {
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->dropForeign(['rate_id']);
            $table->dropUnique(['statamic_id', 'date', 'rate_id']);
        });

        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->string('property')->default('none');
            $table->unsignedBigInteger('rate_id')->nullable()->change();
        });

        // Populate property from rate slug to preserve uniqueness before adding constraint
        DB::statement('
            UPDATE resrv_availabilities
            SET property = (
                SELECT slug FROM resrv_rates
                WHERE resrv_rates.id = resrv_availabilities.rate_id
            )
            WHERE rate_id IS NOT NULL
        ');

        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->unique(['statamic_id', 'date', 'property']);
            $table->index(['statamic_id', 'date', 'property', 'available']);
        });

        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->string('property')->default('none');
        });

        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->string('property')->default('none');
        });

        Schema::table('resrv_fixed_pricing', function (Blueprint $table) {
            $table->dropUnique(['statamic_id', 'days', 'rate_id']);
        });

        // Remove duplicates before restoring old unique constraint
        DB::statement('
            DELETE FROM resrv_fixed_pricing
            WHERE id NOT IN (
                SELECT min_id FROM (
                    SELECT MIN(id) as min_id FROM resrv_fixed_pricing GROUP BY statamic_id, days
                ) AS tmp
            )
        ');

        Schema::table('resrv_fixed_pricing', function (Blueprint $table) {
            $table->unique(['statamic_id', 'days']);
        });
    }
};
