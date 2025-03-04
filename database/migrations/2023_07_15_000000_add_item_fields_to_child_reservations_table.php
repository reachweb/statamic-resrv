<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddItemFieldsToChildReservationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->string('item_id')->nullable()->after('child_reservation_id');
            $table->decimal('price', 10, 2)->nullable()->after('quantity');
            $table->decimal('payment', 10, 2)->nullable()->after('price');
            $table->decimal('total', 10, 2)->nullable()->after('payment');
            $table->index('item_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('resrv_child_reservations', function (Blueprint $table) {
            $table->dropIndex(['item_id']);
            $table->dropColumn(['item_id', 'price', 'payment', 'total']);
        });
    }
}
