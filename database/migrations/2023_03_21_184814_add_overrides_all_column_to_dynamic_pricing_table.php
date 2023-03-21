<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('resrv_dynamic_pricing', function (Blueprint $table) {
            $table->boolean('overrides_all')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('resrv_dynamic_pricing', function (Blueprint $table) {
            $table->dropColumn('overrides_all');
        });
    }
};
