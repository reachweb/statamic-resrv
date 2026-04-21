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
        // SQLite heal: older Laravel versions (pre-12, with DBAL-backed SQLite schema changes)
        // rebuilt `resrv_availabilities` when 2024_03_10_151910_add_property_field_to_resrv_availabilities_table
        // ran with its ->after('price'). SQLite renames the table but not its indexes, so the
        // composite indexes defined by 2024_03_10_152228_add_index_to_resrv_availabilities_table
        // end up stuck under a `new_` prefix, and the rebuild also auto-creates spurious
        // single-column indexes on `date` and `property`. Both break the Schema::table calls
        // below. This block is idempotent and a no-op on installs that never went through the
        // DBAL rebuild path.
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Drop single-column indexes on `date` and `property`. The canonical `date` index
            // is recreated implicitly by the composite index below; `property` is going away
            // with the column drop anyway.
            DB::statement('DROP INDEX IF EXISTS "new_resrv_availabilities_property_index"');
            DB::statement('DROP INDEX IF EXISTS "new_resrv_availabilities_date_index"');
            DB::statement('DROP INDEX IF EXISTS "resrv_availabilities_property_index"');
            DB::statement('DROP INDEX IF EXISTS "resrv_availabilities_date_index"');

            // Rename the two legitimate composite indexes back to their canonical names.
            // SQLite has no ALTER INDEX RENAME — drop + recreate is the only option.
            $renames = [
                'new_resrv_availabilities_statamic_id_date_property_unique' => 'CREATE UNIQUE INDEX "resrv_availabilities_statamic_id_date_property_unique" ON "resrv_availabilities" ("statamic_id", "date", "property")',
                'new_resrv_availabilities_statamic_id_date_property_available_index' => 'CREATE INDEX "resrv_availabilities_statamic_id_date_property_available_index" ON "resrv_availabilities" ("statamic_id", "date", "property", "available")',
            ];

            foreach ($renames as $oldName => $createSql) {
                $exists = DB::selectOne(
                    "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
                    [$oldName]
                );

                if ($exists) {
                    DB::statement('DROP INDEX "'.$oldName.'"');
                    DB::statement($createSql);
                }
            }
        }

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

        // Duplicate fixed pricing across all rates per collection.
        // Migration 3 assigned each row to the first rate; now that the unique constraint
        // includes rate_id, we can safely clone them for the remaining rates.
        $fixedRows = DB::table('resrv_fixed_pricing as fp')
            ->join('resrv_entries as e', 'fp.statamic_id', '=', 'e.item_id')
            ->select('fp.statamic_id', 'fp.days', 'fp.price', 'fp.rate_id', 'e.collection')
            ->get();

        foreach ($fixedRows->groupBy('collection') as $collection => $rows) {
            $allRates = DB::table('resrv_rates')
                ->where('collection', $collection)
                ->orderBy('order')
                ->get();

            if ($allRates->count() <= 1) {
                continue;
            }

            $assignedRateIds = $rows->pluck('rate_id')->unique();

            foreach ($allRates as $rate) {
                if ($assignedRateIds->contains($rate->id)) {
                    continue;
                }

                $uniqueRows = $rows->unique(fn ($r) => $r->statamic_id.'|'.$r->days);

                // Entry-scoped rates: only clone fixed pricing for entries the rate applies to
                if (! $rate->apply_to_all) {
                    $rateEntryIds = DB::table('resrv_rate_entries')
                        ->where('rate_id', $rate->id)
                        ->pluck('statamic_id');

                    $uniqueRows = $uniqueRows->whereIn('statamic_id', $rateEntryIds);
                }

                foreach ($uniqueRows as $row) {
                    DB::table('resrv_fixed_pricing')->insert([
                        'statamic_id' => $row->statamic_id,
                        'days' => $row->days,
                        'price' => $row->price,
                        'rate_id' => $rate->id,
                    ]);
                }
            }
        }

        Schema::dropIfExists('resrv_advanced_availabilities');
    }

    public function down(): void
    {
        // P2: Recreate the legacy advanced availabilities table dropped by up()
        Schema::create('resrv_advanced_availabilities', function (Blueprint $table) {
            $table->string('statamic_id')->index();
            $table->date('date')->index();
            $table->integer('available');
            $table->float('price', 8, 2);
            $table->string('property')->index();
            $table->unique(['statamic_id', 'date', 'property']);
            $table->timestamps();
        });

        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->dropForeign(['rate_id']);
            $table->dropUnique(['statamic_id', 'date', 'rate_id']);
        });

        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->string('property')->default('none');
            $table->unsignedBigInteger('rate_id')->nullable()->change();
        });

        // P3: Map default rate slug back to legacy 'none' property value
        DB::statement("
            UPDATE resrv_availabilities
            SET property = CASE
                WHEN rate_id IS NOT NULL AND (
                    SELECT slug FROM resrv_rates
                    WHERE resrv_rates.id = resrv_availabilities.rate_id
                ) = 'default' THEN 'none'
                WHEN rate_id IS NOT NULL THEN (
                    SELECT slug FROM resrv_rates
                    WHERE resrv_rates.id = resrv_availabilities.rate_id
                )
                ELSE 'none'
            END
        ");

        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->unique(['statamic_id', 'date', 'property']);
            $table->index(['statamic_id', 'date', 'property', 'available']);
        });

        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->string('property')->nullable();
        });

        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->string('property')->nullable();
        });

        // P3: Default rate maps to NULL property on reservations (legacy behavior)
        DB::statement("
            UPDATE resrv_reservations
            SET property = CASE
                WHEN rate_id IS NOT NULL AND (
                    SELECT slug FROM resrv_rates
                    WHERE resrv_rates.id = resrv_reservations.rate_id
                ) = 'default' THEN NULL
                ELSE (
                    SELECT slug FROM resrv_rates
                    WHERE resrv_rates.id = resrv_reservations.rate_id
                )
            END
            WHERE rate_id IS NOT NULL
        ");

        DB::statement("
            UPDATE resrv_child_reservations
            SET property = CASE
                WHEN rate_id IS NOT NULL AND (
                    SELECT slug FROM resrv_rates
                    WHERE resrv_rates.id = resrv_child_reservations.rate_id
                ) = 'default' THEN NULL
                ELSE (
                    SELECT slug FROM resrv_rates
                    WHERE resrv_rates.id = resrv_child_reservations.rate_id
                )
            END
            WHERE rate_id IS NOT NULL
        ");

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
