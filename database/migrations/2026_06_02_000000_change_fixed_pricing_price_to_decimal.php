<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money must never be stored as a float. resrv_fixed_pricing.price was float(8,2),
     * which can't represent 2/3-decimal currency values exactly and caps magnitude at
     * ~999999.99 on MySQL. Switch it to decimal(10,2), matching the convention used by
     * the rate tables (resrv_rates.modifier_amount, resrv_child_reservations.price).
     */
    public function up()
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resrv_fixed_pricing ALTER COLUMN price TYPE decimal(10,2) USING price::decimal(10,2)');
        } else {
            Schema::table('resrv_fixed_pricing', function (Blueprint $table) {
                $table->decimal('price', 10, 2)->change();
            });
        }
    }

    public function down()
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resrv_fixed_pricing ALTER COLUMN price TYPE double precision USING price::double precision');
        } else {
            Schema::table('resrv_fixed_pricing', function (Blueprint $table) {
                $table->float('price', 8, 2)->change();
            });
        }
    }
};
