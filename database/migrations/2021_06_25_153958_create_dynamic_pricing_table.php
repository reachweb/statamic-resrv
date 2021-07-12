<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDynamicPricingTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_dynamic_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('amount_type');
            $table->string('amount_operation');
            $table->float('amount', 8, 2);
            $table->datetime('date_start')->nullable();
            $table->datetime('date_end')->nullable();
            $table->datetime('date_include')->nullable();
            $table->string('condition_type')->nullable();
            $table->string('condition_comparison')->nullable();
            $table->string('condition_value')->nullable();
            $table->integer('order');
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
        Schema::dropIfExists('resrv_dynamic_pricing');
    }
}
