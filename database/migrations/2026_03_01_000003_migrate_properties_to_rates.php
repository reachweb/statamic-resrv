<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Group availability records by collection (via resrv_entries) and property
        $entries = DB::table('resrv_availabilities as a')
            ->join('resrv_entries as e', 'a.statamic_id', '=', 'e.item_id')
            ->select('a.statamic_id', 'a.property', 'e.collection')
            ->distinct()
            ->get()
            ->groupBy('collection');

        foreach ($entries as $collection => $records) {
            $propertySlugs = $records->pluck('property')->unique()->values();
            $order = 0;

            foreach ($propertySlugs as $slug) {
                $rateSlug = $slug === 'none' ? 'default' : $slug;
                $rateTitle = $slug === 'none' ? 'Default' : $slug;

                $existingRate = DB::table('resrv_rates')
                    ->where('collection', $collection)
                    ->where('slug', $rateSlug)
                    ->first();

                if ($existingRate) {
                    $rateId = $existingRate->id;
                } else {
                    $rateId = DB::table('resrv_rates')->insertGetId([
                        'collection' => $collection,
                        'apply_to_all' => true,
                        'title' => $rateTitle,
                        'slug' => $rateSlug,
                        'pricing_type' => 'independent',
                        'availability_type' => 'independent',
                        'refundable' => true,
                        'order' => $order,
                        'published' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Get all statamic_ids for this collection + property
                $statamicIds = $records->where('property', $slug)->pluck('statamic_id')->unique();

                foreach ($statamicIds as $statamicId) {
                    DB::table('resrv_availabilities')
                        ->where('statamic_id', $statamicId)
                        ->where('property', $slug)
                        ->whereNull('rate_id')
                        ->update(['rate_id' => $rateId]);

                    DB::table('resrv_reservations')
                        ->where('item_id', $statamicId)
                        ->where('property', $slug)
                        ->whereNull('rate_id')
                        ->update(['rate_id' => $rateId]);

                    DB::table('resrv_child_reservations')
                        ->whereIn('reservation_id', function ($query) use ($statamicId) {
                            $query->select('id')
                                ->from('resrv_reservations')
                                ->where('item_id', $statamicId);
                        })
                        ->where('property', $slug)
                        ->whereNull('rate_id')
                        ->update(['rate_id' => $rateId]);
                }

                $order++;
            }

            // Assign fixed pricing to first rate in this collection
            $defaultRate = DB::table('resrv_rates')
                ->where('collection', $collection)
                ->orderBy('order')
                ->first();

            if ($defaultRate) {
                $statamicIdsInCollection = $records->pluck('statamic_id')->unique();

                foreach ($statamicIdsInCollection as $statamicId) {
                    DB::table('resrv_fixed_pricing')
                        ->where('statamic_id', $statamicId)
                        ->whereNull('rate_id')
                        ->update(['rate_id' => $defaultRate->id]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('resrv_availabilities')->update(['rate_id' => null]);
        DB::table('resrv_reservations')->update(['rate_id' => null]);
        DB::table('resrv_child_reservations')->update(['rate_id' => null]);
        DB::table('resrv_fixed_pricing')->update(['rate_id' => null]);

        DB::table('resrv_rates')->delete();
    }
};
