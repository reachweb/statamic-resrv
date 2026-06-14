<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sparse "exception" pivot: a row means this option value is DISABLED for this entry.
        // Absence of a row means the value is enabled — so global options default to fully available
        // and only deviations are stored (no backfill of every value/entry pair required).
        Schema::create('resrv_option_value_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_value_id')->constrained('resrv_options_values')->cascadeOnDelete();
            $table->string('statamic_id');
            $table->timestamps();

            $table->unique(['option_value_id', 'statamic_id']);
            $table->index('statamic_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resrv_option_value_entries');
    }
};
