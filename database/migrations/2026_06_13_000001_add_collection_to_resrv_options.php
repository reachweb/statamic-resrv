<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global, collection-scoped options (mirrors the Rate architecture).
        Schema::table('resrv_options', function (Blueprint $table) {
            $table->string('collection')->nullable()->after('id')->index();
            $table->boolean('apply_to_all')->default(false)->after('collection');
        });

        Schema::create('resrv_option_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_id')->constrained('resrv_options')->cascadeOnDelete();
            $table->string('statamic_id');
            $table->timestamps();

            $table->unique(['option_id', 'statamic_id']);
            $table->index('statamic_id');
        });

        // Snapshot the selected value's name, computed price and price type onto the reservation
        // pivot at checkout (mirrors resrv_reservation_extra.price) so that sharing a global option
        // across entries can never retroactively mutate the price/name shown on past reservations.
        // Nullable: historical rows have no snapshot and fall back to the live value at read time.
        Schema::table('resrv_reservation_option', function (Blueprint $table) {
            $table->string('value_name')->nullable()->after('value');
            $table->string('price')->nullable()->after('value_name');
            $table->string('price_type')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('resrv_reservation_option', function (Blueprint $table) {
            $table->dropColumn(['value_name', 'price', 'price_type']);
        });

        Schema::dropIfExists('resrv_option_entries');

        Schema::table('resrv_options', function (Blueprint $table) {
            $table->dropColumn(['collection', 'apply_to_all']);
        });
    }
};
