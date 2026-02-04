<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fixes the option_id column type from string to bigint for PostgreSQL compatibility.
     */
    public function up()
    {
        // For PostgreSQL, we need to cast the existing data
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resrv_options_values ALTER COLUMN option_id TYPE bigint USING option_id::bigint');
        } else {
            Schema::table('resrv_options_values', function (Blueprint $table) {
                $table->unsignedBigInteger('option_id')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resrv_options_values ALTER COLUMN option_id TYPE varchar(255) USING option_id::varchar');
        } else {
            Schema::table('resrv_options_values', function (Blueprint $table) {
                $table->string('option_id')->change();
            });
        }
    }
};
