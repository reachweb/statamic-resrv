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
        Schema::create('resrv_entries', function (Blueprint $table) {
            $table->id();
            $table->string('item_id', 36)->unique();
            $table->string('title');
            $table->boolean('enabled')->default(true);
            $table->string('collection');
            $table->string('handle');
            $table->json('options')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('resrv_entries');
    }
};
