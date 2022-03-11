<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPropertyColumnToReservationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('reservations', function (Blueprint $table) {
            Schema::table('resrv_reservations', function (Blueprint $table) {
                $table->after('quantity', function ($table) {
                    $table->string('property')->nullable();
                });
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
        Schema::table('reservations', function (Blueprint $table) {
            Schema::table('resrv_reservations', function (Blueprint $table) {
                $table->dropColumn('property');
            });
        });
    }
}
