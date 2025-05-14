<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->integer('available_blocked')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->dropColumn('available_blocked');
        });
    }
};
