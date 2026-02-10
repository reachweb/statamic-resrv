<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->unsignedBigInteger('rate_id')->nullable()->after('property');
            $table->index('rate_id');
        });

        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('rate_id')->nullable()->after('property');
            $table->index('rate_id');
        });

        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('rate_id')->nullable()->after('property');
            $table->index('rate_id');
        });

        Schema::table('resrv_fixed_pricing', function (Blueprint $table) {
            $table->unsignedBigInteger('rate_id')->nullable();
            $table->index('rate_id');
        });
    }

    public function down(): void
    {
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->dropIndex(['rate_id']);
            $table->dropColumn('rate_id');
        });

        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropIndex(['rate_id']);
            $table->dropColumn('rate_id');
        });

        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->dropIndex(['rate_id']);
            $table->dropColumn('rate_id');
        });

        Schema::table('resrv_fixed_pricing', function (Blueprint $table) {
            $table->dropIndex(['rate_id']);
            $table->dropColumn('rate_id');
        });
    }
};
