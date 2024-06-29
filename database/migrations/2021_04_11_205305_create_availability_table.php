<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAvailabilityTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_availabilities', function (Blueprint $table) {
            $table->id();
            $table->string('statamic_id')->index();
            $table->date('date')->index();
            $table->integer('available');
            $table->float('price', 8, 2);
            $table->unique(['statamic_id', 'date']);
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
        Schema::dropIfExists('resrv_availabilities');
    }
}
