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
        Schema::table('resrv_extras', function (Blueprint $table) {
            $table->string('custom')->after('price_type')->nullable();
            $table->string('override_label')->after('price_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('resrv_extras', function (Blueprint $table) {
            $table->dropColumn('custom');
            $table->dropColumn('override_label');
        });
    }
};
