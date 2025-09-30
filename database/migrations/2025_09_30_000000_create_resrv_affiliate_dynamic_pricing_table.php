<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('resrv_affiliate_dynamic_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('resrv_affiliates')->onDelete('cascade');
            $table->foreignId('dynamic_pricing_id')->unique()->constrained('resrv_dynamic_pricing')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('resrv_affiliate_dynamic_pricing');
    }
};
