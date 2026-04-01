<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
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

                $allCollectionEntryIds = DB::table('resrv_entries')
                    ->where('collection', $collection)
                    ->pluck('item_id');

                foreach ($propertySlugs as $slug) {
                    $rateSlug = $slug === 'none' ? 'default' : $slug;
                    $rateTitle = $slug === 'none' ? 'Default' : $slug;

                    $statamicIds = $records->where('property', $slug)->pluck('statamic_id')->unique();

                    $applyToAll = $allCollectionEntryIds->isNotEmpty()
                        && $allCollectionEntryIds->diff($statamicIds)->isEmpty();

                    $existingRate = DB::table('resrv_rates')
                        ->where('collection', $collection)
                        ->where('slug', $rateSlug)
                        ->first();

                    if ($existingRate) {
                        $rateId = $existingRate->id;
                    } else {
                        $rateId = DB::table('resrv_rates')->insertGetId([
                            'collection' => $collection,
                            'apply_to_all' => $applyToAll,
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

                    if (! $applyToAll) {
                        $existingPivotIds = DB::table('resrv_rate_entries')
                            ->where('rate_id', $rateId)
                            ->pluck('statamic_id');

                        foreach ($statamicIds->diff($existingPivotIds) as $statamicId) {
                            DB::table('resrv_rate_entries')->insert([
                                'rate_id' => $rateId,
                                'statamic_id' => $statamicId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }

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

                // Fixed pricing was not property-scoped in the old schema (no property column
                // on resrv_fixed_pricing), so we assign it to the default/first rate per collection.
                // The finalize migration will duplicate it across all rates after the unique
                // constraint changes from (statamic_id, days) to (statamic_id, days, rate_id).
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

            // Second pass: discover properties that only exist in reservations (no availability rows).
            // This handles cases where old availability was pruned but historical reservations remain.
            $reservationProperties = DB::table('resrv_reservations as r')
                ->join('resrv_entries as e', 'r.item_id', '=', 'e.item_id')
                ->select('r.item_id as statamic_id', 'r.property', 'e.collection')
                ->whereNotNull('r.property')
                ->whereNull('r.rate_id')
                ->distinct()
                ->get()
                ->groupBy('collection');

            foreach ($reservationProperties as $collection => $records) {
                $propertySlugs = $records->pluck('property')->unique()->values();

                $allCollectionEntryIds = DB::table('resrv_entries')
                    ->where('collection', $collection)
                    ->pluck('item_id');

                foreach ($propertySlugs as $slug) {
                    $rateSlug = $slug === 'none' ? 'default' : $slug;
                    $rateTitle = $slug === 'none' ? 'Default' : $slug;

                    $statamicIds = $records->where('property', $slug)->pluck('statamic_id')->unique();

                    $applyToAll = $allCollectionEntryIds->isNotEmpty()
                        && $allCollectionEntryIds->diff($statamicIds)->isEmpty();

                    $existingRate = DB::table('resrv_rates')
                        ->where('collection', $collection)
                        ->where('slug', $rateSlug)
                        ->first();

                    if ($existingRate) {
                        $rateId = $existingRate->id;
                    } else {
                        $maxOrder = DB::table('resrv_rates')
                            ->where('collection', $collection)
                            ->max('order') ?? -1;

                        $rateId = DB::table('resrv_rates')->insertGetId([
                            'collection' => $collection,
                            'apply_to_all' => $applyToAll,
                            'title' => $rateTitle,
                            'slug' => $rateSlug,
                            'pricing_type' => 'independent',
                            'availability_type' => 'independent',
                            'refundable' => true,
                            'order' => $maxOrder + 1,
                            'published' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    if (! $applyToAll) {
                        $existingPivotIds = DB::table('resrv_rate_entries')
                            ->where('rate_id', $rateId)
                            ->pluck('statamic_id');

                        foreach ($statamicIds->diff($existingPivotIds) as $statamicId) {
                            DB::table('resrv_rate_entries')->insert([
                                'rate_id' => $rateId,
                                'statamic_id' => $statamicId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }

                    foreach ($statamicIds as $statamicId) {
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
                }
            }

            // Third pass: handle reservations with null property (standard non-advanced bookings).
            // In the old schema, standard reservations stored property as null while availability
            // used 'none'. Assign these to the default rate for their collection.
            $nullPropertyReservations = DB::table('resrv_reservations as r')
                ->join('resrv_entries as e', 'r.item_id', '=', 'e.item_id')
                ->select('r.item_id as statamic_id', 'e.collection')
                ->whereNull('r.property')
                ->whereNull('r.rate_id')
                ->distinct()
                ->get()
                ->groupBy('collection');

            foreach ($nullPropertyReservations as $collection => $records) {
                $defaultRate = DB::table('resrv_rates')
                    ->where('collection', $collection)
                    ->where('slug', 'default')
                    ->first();

                if (! $defaultRate) {
                    $maxOrder = DB::table('resrv_rates')
                        ->where('collection', $collection)
                        ->max('order') ?? -1;

                    $allCollectionEntryIds = DB::table('resrv_entries')
                        ->where('collection', $collection)
                        ->pluck('item_id');

                    $statamicIds = $records->pluck('statamic_id')->unique();

                    $applyToAll = $allCollectionEntryIds->isNotEmpty()
                        && $allCollectionEntryIds->diff($statamicIds)->isEmpty();

                    $defaultRateId = DB::table('resrv_rates')->insertGetId([
                        'collection' => $collection,
                        'apply_to_all' => $applyToAll,
                        'title' => 'Default',
                        'slug' => 'default',
                        'pricing_type' => 'independent',
                        'availability_type' => 'independent',
                        'refundable' => true,
                        'order' => $maxOrder + 1,
                        'published' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $defaultRate = DB::table('resrv_rates')->find($defaultRateId);

                    if (! $applyToAll) {
                        foreach ($statamicIds as $statamicId) {
                            DB::table('resrv_rate_entries')->insert([
                                'rate_id' => $defaultRateId,
                                'statamic_id' => $statamicId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }

                $statamicIds = $records->pluck('statamic_id')->unique();

                foreach ($statamicIds as $statamicId) {
                    DB::table('resrv_reservations')
                        ->where('item_id', $statamicId)
                        ->whereNull('property')
                        ->whereNull('rate_id')
                        ->update(['rate_id' => $defaultRate->id]);

                    DB::table('resrv_child_reservations')
                        ->whereIn('reservation_id', function ($query) use ($statamicId) {
                            $query->select('id')
                                ->from('resrv_reservations')
                                ->where('item_id', $statamicId);
                        })
                        ->whereNull('property')
                        ->whereNull('rate_id')
                        ->update(['rate_id' => $defaultRate->id]);
                }
            }
        });
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
