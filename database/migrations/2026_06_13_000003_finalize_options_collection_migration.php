<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the index before the column in a separate closure: SQLite rebuilds the table on a
        // column drop and chokes on a still-referenced index (same pattern as the Rate finalize migration).
        Schema::table('resrv_options', function (Blueprint $table) {
            $table->dropIndex(['item_id']);
        });

        Schema::table('resrv_options', function (Blueprint $table) {
            $table->dropColumn('item_id');
        });
    }

    public function down(): void
    {
        Schema::table('resrv_options', function (Blueprint $table) {
            $table->string('item_id')->default('');
        });

        // Best-effort restore: each option's first attached entry becomes its legacy item_id.
        foreach (DB::table('resrv_options')->get() as $option) {
            $itemId = DB::table('resrv_option_entries')
                ->where('option_id', $option->id)
                ->value('statamic_id') ?? '';

            DB::table('resrv_options')->where('id', $option->id)->update(['item_id' => $itemId]);
        }

        Schema::table('resrv_options', function (Blueprint $table) {
            $table->index('item_id');
        });
    }
};
