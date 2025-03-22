<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Reach\StatamicResrv\Models\Customer;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip if reservations table doesn't exist yet (fresh install)
        if (! Schema::hasTable('resrv_reservations')) {
            return;
        }

        // Get all reservations with customer data
        $reservations = DB::table('resrv_reservations')
            ->whereNotNull('customer')
            ->whereNot('customer', '[]')
            ->whereNot('customer', '""')
            ->get();

        // Create a new customer record for each reservation
        foreach ($reservations as $reservation) {
            $customerData = json_decode($reservation->customer, true);

            // Skip if there's no email
            if (empty($customerData['email'])) {
                continue;
            }

            // Create a new customer record for each reservation
            $customer = Customer::create([
                'email' => $customerData['email'],
                'data' => collect($customerData)->except('email'),
            ]);

            // Update the reservation with the new customer_id
            DB::table('resrv_reservations')
                ->where('id', $reservation->id)
                ->update(['customer_id' => $customer->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is not reversible as it modifies data
    }
};
