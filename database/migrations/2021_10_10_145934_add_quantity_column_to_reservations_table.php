<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddQuantityColumnToReservationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->after('date_end', function ($table) {
                $table->integer('quantity')->default(1);
            });
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
            $table->dropColumn('quantity');
        });
    }
}
