<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAdvancedAvailabilitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_advanced_availabilities', function (Blueprint $table) {
            $table->string('statamic_id')->index();
            $table->date('date')->index();
            $table->integer('available');
            $table->float('price', 8, 2);
            $table->string('property')->index();
            $table->unique(['statamic_id', 'date', 'property']);
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
        Schema::dropIfExists('resrv_advanced_availabilities');
    }
}
