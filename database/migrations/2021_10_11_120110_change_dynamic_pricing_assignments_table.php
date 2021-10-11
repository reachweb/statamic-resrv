<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeDynamicPricingAssignmentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('resrv_dynamic_pricing_assignments', function (Blueprint $table) {
            $table->string('dynamic_pricing_assignment_id')->change();
            $table->string('dynamic_pricing_assignment_type')->change();            
        });
    }
}
