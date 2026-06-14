<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A generic, domain-neutral conditional fee: compare the customer's selected value in two
        // Options; if they differ (or match), add a flat price. "One-way rental fee" is one instance.
        Schema::create('resrv_surcharges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->foreignId('first_option_id')->nullable()->constrained('resrv_options')->nullOnDelete();
            $table->foreignId('second_option_id')->nullable()->constrained('resrv_options')->nullOnDelete();
            $table->string('comparison')->default('differs'); // differs | matches
            $table->string('price')->default('0.00'); // major-unit decimal, cast via PriceClass
            $table->integer('order')->default(0);
            $table->boolean('published')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resrv_surcharges');
    }
};
