<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixes the reservation_id column type from string to bigint to match
     * resrv_reservations.id, so PostgreSQL doesn't reject joins on type mismatch.
     */
    public function up()
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resrv_child_reservations ALTER COLUMN reservation_id TYPE bigint USING reservation_id::bigint');
        } else {
            Schema::table('resrv_child_reservations', function (Blueprint $table) {
                $table->unsignedBigInteger('reservation_id')->change();
            });
        }
    }

    public function down()
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE resrv_child_reservations ALTER COLUMN reservation_id TYPE varchar(255) USING reservation_id::varchar');
        } else {
            Schema::table('resrv_child_reservations', function (Blueprint $table) {
                $table->string('reservation_id')->change();
            });
        }
    }
};
