<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('new_resrv_availabilities', function (Blueprint $table) {
            $table->id(); // Add the new primary key column
            $table->uuid('statamic_id');
            $table->date('date');
            $table->string('property')->nullable();
            $table->integer('available');
            $table->decimal('price', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['statamic_id', 'date', 'property']);
            $table->index(['statamic_id', 'date', 'property', 'available']);
            $table->index(['property']);
            $table->index(['date']);
        });

        // Copy data from old table to new table
        DB::statement('
            INSERT INTO new_resrv_availabilities (statamic_id, date, property, available, price, created_at, updated_at)
            SELECT statamic_id, date, property, available, price, created_at, updated_at FROM resrv_availabilities
        ');

        // Drop the old table
        Schema::drop('resrv_availabilities');

        // Rename the new table to the original table name
        Schema::rename('new_resrv_availabilities', 'resrv_availabilities');
    }

    public function down()
    {
        Schema::create('old_resrv_availabilities', function (Blueprint $table) {
            $table->uuid('statamic_id');
            $table->date('date');
            $table->string('property')->nullable();
            $table->integer('available');
            $table->decimal('price', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['statamic_id', 'date', 'property']);
            $table->index(['statamic_id', 'date', 'property', 'available']);
            $table->index(['property']);
            $table->index(['date']);
        });

        // Copy data back from the new table to the old table
        DB::statement('
            INSERT INTO old_resrv_availabilities (statamic_id, date, property, available, price, created_at, updated_at)
            SELECT statamic_id, date, property, available, price, created_at, updated_at FROM resrv_availabilities
        ');

        // Drop the new table
        Schema::drop('resrv_availabilities');

        // Rename the old table back to the original table name
        Schema::rename('old_resrv_availabilities', 'resrv_availabilities');
    }
};
