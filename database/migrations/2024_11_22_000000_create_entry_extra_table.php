<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('resrv_entry_extra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('resrv_entries')->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained('resrv_extras')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('resrv_entry_extra');
    }
};
