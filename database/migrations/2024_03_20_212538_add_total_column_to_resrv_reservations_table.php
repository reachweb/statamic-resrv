<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->float('total', 8, 2)->nullable()->after('payment');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('total');
        });
    }
};
