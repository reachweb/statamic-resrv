<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReservationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('status')->index();
            $table->string('reference')->index();
            $table->string('item_id')->index();
            $table->datetime('date_start');
            $table->datetime('date_end');
            $table->string('location_start')->nullable();
            $table->string('location_end')->nullable();
            $table->float('price', 8, 2);
            $table->float('payment', 8, 2);
            $table->string('payment_id')->collation('utf8_bin');
            $table->json('customer');
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
        Schema::dropIfExists('resrv_reservations');
    }
}
