<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTypeColumnToReservationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->after('id', function ($table) {
                $table->string('type')->default('normal');
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
            $table->dropColumn('type');
        });
    }
}
