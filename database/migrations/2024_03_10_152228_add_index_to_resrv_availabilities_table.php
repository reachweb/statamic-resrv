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
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->dropUnique(['statamic_id', 'date']);
            $table->unique(['statamic_id', 'date', 'property']);
            $table->index(['statamic_id', 'date', 'property', 'available']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->dropUnique(['statamic_id', 'date', 'property']);
            $table->dropIndex(['statamic_id', 'date', 'property', 'available']);
        });
    }
};
