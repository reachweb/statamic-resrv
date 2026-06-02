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
     * ~999999.99 on MySQL.
     *
     * The scale must cover every ISO 4217 minor unit, not just 2: BHD/KWD/OMR use 3 and
     * CLF/UYW use 4. A decimal(10,2) column would let MySQL/Postgres silently round an
     * admin-entered fixed price like 1.234 (BHD) down to 1.23 before
     * FixedPricing::getFixedPricing() hands it to the currency-aware Price::create(),
     * charging the wrong amount. decimal(12,4) round-trips all of them and keeps the same
     * 8 integer digits of magnitude as decimal(10,2).
     */
    public function up()
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resrv_fixed_pricing ALTER COLUMN price TYPE decimal(12,4) USING price::decimal(12,4)');
        } else {
            Schema::table('resrv_fixed_pricing', function (Blueprint $table) {
                $table->decimal('price', 12, 4)->change();
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
