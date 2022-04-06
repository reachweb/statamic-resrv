<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddChildReservationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_child_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_id')->index();
            $table->datetime('date_start');
            $table->datetime('date_end');
            $table->integer('quantity')->default(1);
            $table->string('property')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('resrv_child_reservations');
    }
}
