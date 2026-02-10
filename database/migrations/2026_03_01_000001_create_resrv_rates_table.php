<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resrv_rates', function (Blueprint $table) {
            $table->id();
            $table->string('statamic_id')->index();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();

            // Pricing
            $table->string('pricing_type', 20)->default('independent');
            $table->foreignId('base_rate_id')->nullable()->constrained('resrv_rates')->nullOnDelete();
            $table->string('modifier_type', 20)->nullable();
            $table->string('modifier_operation', 20)->nullable();
            $table->decimal('modifier_amount', 10, 2)->nullable();

            // Availability
            $table->string('availability_type', 20)->default('independent');
            $table->integer('max_available')->nullable();

            // Restrictions
            $table->date('date_start')->nullable();
            $table->date('date_end')->nullable();
            $table->integer('min_days_before')->nullable();
            $table->integer('min_stay')->nullable();
            $table->integer('max_stay')->nullable();

            // Policy
            $table->boolean('refundable')->default(true);

            // Meta
            $table->integer('order')->default(0);
            $table->boolean('published')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['statamic_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resrv_rates');
    }
};
