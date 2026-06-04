<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_dynamic_pricing', function (Blueprint $table) {
            $table->boolean('published')->default(true);
        });

        DB::table('resrv_dynamic_pricing')->update(['published' => true]);
    }

    public function down(): void
    {
        Schema::table('resrv_dynamic_pricing', function (Blueprint $table) {
            $table->dropColumn('published');
        });
    }
};
