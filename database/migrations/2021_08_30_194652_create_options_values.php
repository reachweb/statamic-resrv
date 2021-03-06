<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOptionsValues extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_options_values', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('option_id')->index();
            $table->float('price', 8, 2);
            $table->string('price_type');
            $table->text('description')->nullable();
            $table->integer('order');
            $table->boolean('published');
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
        Schema::dropIfExists('resrv_options_values');
    }
}
