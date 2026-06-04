<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resrv_rates', function (Blueprint $table) {
            $table->boolean('require_price_override')->default(false)->after('availability_type');
        });

        Schema::create('resrv_rate_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_id')->constrained('resrv_rates')->cascadeOnDelete();
            $table->string('statamic_id');
            $table->date('date');
            $table->float('price', 8, 2);
            $table->timestamps();

            $table->unique(['rate_id', 'statamic_id', 'date'], 'resrv_rate_prices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resrv_rate_prices');

        Schema::table('resrv_rates', function (Blueprint $table) {
            $table->dropColumn('require_price_override');
        });
    }
};
