<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Raw query bypasses the SoftDeletes scope so soft-deleted (shared/historical) options
            // are migrated too — forEntry() resolves them withTrashed for past reservations.
            foreach (DB::table('resrv_options')->get() as $option) {
                $itemId = $option->item_id;

                $collection = ! empty($itemId)
                    ? DB::table('resrv_entries')->where('item_id', $itemId)->value('collection')
                    : null;

                // Each option keeps the exact entry it was bound to (apply_to_all=false). A null
                // collection (deleted entry or empty item_id) leaves the option unattached, mirroring
                // Rate's whereNull('id') hide behavior in the forEntry scope.
                DB::table('resrv_options')->where('id', $option->id)->update([
                    'collection' => $collection,
                    'apply_to_all' => false,
                ]);

                if (! empty($itemId)) {
                    $exists = DB::table('resrv_option_entries')
                        ->where('option_id', $option->id)
                        ->where('statamic_id', $itemId)
                        ->exists();

                    if (! $exists) {
                        DB::table('resrv_option_entries')->insert([
                            'option_id' => $option->id,
                            'statamic_id' => $itemId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('resrv_option_entries')->delete();
        DB::table('resrv_options')->update(['collection' => null, 'apply_to_all' => false]);
    }
};
