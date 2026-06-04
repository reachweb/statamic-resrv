<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->index(['date', 'available', 'rate_id', 'statamic_id'], 'resrv_avail_search_idx');
        });
    }

    public function down(): void
    {
        Schema::table('resrv_availabilities', function (Blueprint $table) {
            $table->dropIndex('resrv_avail_search_idx');
        });
    }
};
