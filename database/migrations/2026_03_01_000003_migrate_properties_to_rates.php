<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $entries = DB::table('resrv_availabilities')
            ->select('statamic_id', 'property')
            ->distinct()
            ->get()
            ->groupBy('statamic_id');

        foreach ($entries as $statamicId => $properties) {
            $propertySlugs = $properties->pluck('property')->unique()->values();
            $order = 0;

            foreach ($propertySlugs as $slug) {
                $rateSlug = $slug === 'none' ? 'default' : $slug;
                $rateTitle = $slug === 'none' ? 'Default' : $slug;

                $existingRate = DB::table('resrv_rates')
                    ->where('statamic_id', $statamicId)
                    ->where('slug', $rateSlug)
                    ->first();

                if ($existingRate) {
                    $rateId = $existingRate->id;
                } else {
                    $rateId = DB::table('resrv_rates')->insertGetId([
                        'statamic_id' => $statamicId,
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

                $order++;
            }

            $defaultRate = DB::table('resrv_rates')
                ->where('statamic_id', $statamicId)
                ->orderBy('order')
                ->first();

            if ($defaultRate) {
                DB::table('resrv_fixed_pricing')
                    ->where('statamic_id', $statamicId)
                    ->whereNull('rate_id')
                    ->update(['rate_id' => $defaultRate->id]);
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
