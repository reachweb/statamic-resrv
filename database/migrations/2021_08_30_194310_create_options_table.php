<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOptionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_options', function (Blueprint $table) {
            $table->id();
            $table->string('item_id')->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->integer('order');
            $table->boolean('required');
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
        Schema::dropIfExists('resrv_options');
    }
}
