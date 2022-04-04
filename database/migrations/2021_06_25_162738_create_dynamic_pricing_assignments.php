<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDynamicPricingAssignments extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_dynamic_pricing_assignments', function (Blueprint $table) {
            $table->integer('dynamic_pricing_id');
            $table->integer('dynamic_pricing_assignment_id');
            $table->integer('dynamic_pricing_assignment_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('resrv_dynamic_pricing_assignments');
    }
}
