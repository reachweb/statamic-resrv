<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::table('resrv_statamicentry_extra')
            ->join('resrv_entries', 'resrv_entries.item_id', '=', 'resrv_statamicentry_extra.statamicentry_id')
            ->orderBy('resrv_statamicentry_extra.statamicentry_id')
            ->each(function ($row) {
                DB::table('resrv_entry_extra')->insert([
                    'entry_id' => $row->id,
                    'extra_id' => $row->extra_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down()
    {
        DB::table('resrv_entry_extra')->truncate();
    }
};
