<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFixedPricingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_fixed_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('statamic_id')->index();
            $table->string('days')->index();
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
        Schema::dropIfExists('resrv_fixed_pricing');
    }
}
