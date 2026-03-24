<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety: remove availability rows with no rate_id (orphaned records not handled by migration 3)
        $deleted = DB::table('resrv_availabilities')->whereNull('rate_id')->delete();
        if ($deleted > 0) {
            Log::warning("Resrv rate migration: removed {$deleted} orphaned availability rows with null rate_id");
        }

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

        // Duplicate fixed pricing for all rates in each collection.
        // Migration 3 assigned each entry's fixed pricing to the default (first) rate only,
        // but in the old schema fixed pricing was entry-level and applied to all properties.
        $collections = DB::table('resrv_rates')->distinct()->pluck('collection');

        foreach ($collections as $collection) {
            $rates = DB::table('resrv_rates')->where('collection', $collection)->orderBy('order')->get();

            if ($rates->count() <= 1) {
                continue;
            }

            $defaultRate = $rates->first();
            $otherRates = $rates->skip(1);

            $fixedRows = DB::table('resrv_fixed_pricing')
                ->where('rate_id', $defaultRate->id)
                ->get();

            foreach ($otherRates as $rate) {
                foreach ($fixedRows as $row) {
                    DB::table('resrv_fixed_pricing')->insert([
                        'statamic_id' => $row->statamic_id,
                        'days' => $row->days,
                        'price' => $row->price,
                        'rate_id' => $rate->id,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ]);
                }
            }
        }

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
