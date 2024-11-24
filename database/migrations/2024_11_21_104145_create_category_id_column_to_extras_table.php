<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('resrv_extras', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('id')
                ->constrained('resrv_extra_categories')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        // Schema::table('resrv_extras', function (Blueprint $table) {
        //     $table->dropConstrainedForeignId('category_id');
        // });
    }
};
