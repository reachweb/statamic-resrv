<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            // When the reservation became live (confirmed or partner): the moment the guest
            // committed, which the booked cancellation label is judged against. NULL for
            // reservations confirmed before this column existed.
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservations', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
