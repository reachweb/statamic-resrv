<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class PaymentSettlementMigrationTest extends TestCase
{
    public function test_the_backfill_keeps_the_amount_each_booking_received()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => ['class' => FakePaymentGateway::class],
            'offline' => ['class' => OfflinePaymentGateway::class],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $migration = include __DIR__.'/../../database/migrations/2026_11_18_000001_add_payment_settlement_to_resrv_reservations_table.php';
        $migration->down();

        // Rows as v6.5 stored them, each with the amount v6.5's amountReceived() reported for a
        // payment of 30.00 plus a 2.00 surcharge, and whether the CP flagged its payment_id.
        $cases = [
            'paid through the gateway' => [['status' => 'confirmed', 'payment_id' => 'pi_paid'], 'gateway', '32.00'],
            'marked as paid' => [['status' => 'confirmed', 'created_by' => '1'], 'out_of_band', '32.00'],
            'marked as paid, intent kept' => [['status' => 'confirmed', 'created_by' => '1', 'payment_id' => 'pi_kept', 'payment_unresolved' => true], 'out_of_band', '32.00'],
            'zero payment' => [['status' => 'confirmed', 'payment' => 0, 'payment_surcharge' => 0], 'none', '0.00'],
            'refunded gateway charge' => [['status' => 'refunded', 'payment_id' => 'pi_refunded'], 'gateway', '32.00'],
            'refunded after marked as paid' => [['status' => 'refunded', 'created_by' => '1'], 'out_of_band', '32.00'],
            'refunded after marked as paid, intent kept' => [['status' => 'refunded', 'created_by' => '1', 'payment_id' => 'pi_kept_refunded', 'payment_unresolved' => true], 'out_of_band', '32.00'],
            'refunded without a charge' => [['status' => 'refunded'], 'none', '0.00'],
            'cancelled keeping its charge' => [['status' => 'cancelled', 'payment_id' => 'pi_retained'], 'gateway', '32.00'],
            'cancelled unpaid, intent unverified' => [['status' => 'cancelled', 'created_by' => '1', 'payment_id' => 'pi_unverified', 'payment_unresolved' => true], 'unresolved', null],
            'cancelled unpaid' => [['status' => 'cancelled', 'created_by' => '1'], 'none', '0.00'],
            'offline checkout confirmed' => [['status' => 'confirmed', 'payment_id' => 'offline_a', 'payment_gateway' => 'offline'], 'unresolved', null],
            'offline checkout refunded' => [['status' => 'refunded', 'payment_id' => 'offline_b', 'payment_gateway' => 'offline'], 'unresolved', null],
            'offline manual booking marked as paid' => [['status' => 'confirmed', 'created_by' => '1', 'payment_gateway' => 'offline'], 'out_of_band', '32.00'],
            'partner' => [['status' => 'partner'], 'none', '0.00'],
            'pending with an intent' => [['status' => 'pending', 'payment_id' => 'pi_open'], 'none', '0.00'],
            'awaiting payment' => [['status' => 'awaiting_payment', 'created_by' => '1', 'payment_id' => 'pi_link'], 'none', '0.00'],
            'expired' => [['status' => 'expired', 'payment_id' => 'pi_abandoned'], 'none', '0.00'],
        ];

        $ids = [];

        foreach ($cases as $name => [$row]) {
            $ids[$name] = DB::table('resrv_reservations')->insertGetId(array_merge([
                'type' => 'normal',
                'reference' => 'REF',
                'item_id' => 'item',
                'date_start' => now()->addDay(),
                'date_end' => now()->addDays(3),
                'quantity' => 1,
                'price' => 100,
                'payment' => 30,
                'payment_surcharge' => 2,
                'total' => 100,
                'payment_id' => '',
                'payment_gateway' => 'fake',
                'created_at' => now(),
                'updated_at' => now(),
            ], $row));
        }

        $migration->up();

        foreach ($cases as $name => [$row, $settlement, $received]) {
            $reservation = Reservation::find($ids[$name]);

            $this->assertSame($settlement, $reservation->payment_settlement->value, $name);
            $this->assertSame($received, $reservation->amountReceived()?->format(), $name);
            $this->assertSame($row['payment_unresolved'] ?? false, $reservation->hasUnresolvedPayment(), $name);
        }

        // The checkout's made-up offline references are dropped; every other reference stays.
        $this->assertSame('', Reservation::find($ids['offline checkout confirmed'])->payment_id);
        $this->assertSame('', Reservation::find($ids['offline checkout refunded'])->payment_id);
        $this->assertSame('pi_unverified', Reservation::find($ids['cancelled unpaid, intent unverified'])->payment_id);
        $this->assertSame('pi_open', Reservation::find($ids['pending with an intent'])->payment_id);
    }
}
