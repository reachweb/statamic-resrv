<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Mail\ReservationAbandoned;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationAbandonedTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sends_email_for_expired_reservations_with_customer()
    {
        Config::set('resrv-config.enable_abandoned_emails', true);

        Mail::fake();

        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
            'updated_at' => Carbon::yesterday(),
        ])->withCustomer()->create();

        $this->artisan('resrv:send-abandoned-emails')
            ->expectsOutputToContain('1 abandoned reservation email(s)')
            ->assertSuccessful();

        Mail::assertSent(ReservationAbandoned::class, function ($mail) use ($reservation) {
            return $mail->hasTo($reservation->customer->email);
        });
    }

    public function test_command_excludes_customers_with_confirmed_reservation()
    {
        Config::set('resrv-config.enable_abandoned_emails', true);

        Mail::fake();

        $item = $this->makeStatamicItem();
        $customer = Customer::factory()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
            'customer_id' => $customer->id,
            'updated_at' => Carbon::yesterday(),
        ])->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'customer_id' => $customer->id,
        ])->create();

        $this->artisan('resrv:send-abandoned-emails')
            ->expectsOutputToContain('All abandoned customers already have confirmed reservations')
            ->assertSuccessful();

        Mail::assertNotSent(ReservationAbandoned::class);
    }

    public function test_command_sends_one_email_per_unique_customer()
    {
        Config::set('resrv-config.enable_abandoned_emails', true);

        Mail::fake();

        $item = $this->makeStatamicItem();
        $customer = Customer::factory()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
            'customer_id' => $customer->id,
            'updated_at' => Carbon::yesterday(),
        ])->count(3)->create();

        $this->artisan('resrv:send-abandoned-emails')
            ->expectsOutputToContain('1 abandoned reservation email(s)')
            ->assertSuccessful();

        Mail::assertSent(ReservationAbandoned::class, 1);
    }

    public function test_command_does_nothing_when_disabled()
    {
        Config::set('resrv-config.enable_abandoned_emails', false);

        Mail::fake();

        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
            'updated_at' => Carbon::yesterday(),
        ])->withCustomer()->create();

        $this->artisan('resrv:send-abandoned-emails')
            ->expectsOutputToContain('Abandoned reservation emails are disabled')
            ->assertSuccessful();

        Mail::assertNotSent(ReservationAbandoned::class);
    }

    public function test_command_ignores_reservations_without_customer()
    {
        Config::set('resrv-config.enable_abandoned_emails', true);

        Mail::fake();

        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
            'updated_at' => Carbon::yesterday(),
        ])->create();

        $this->artisan('resrv:send-abandoned-emails')
            ->expectsOutputToContain('No abandoned reservations found')
            ->assertSuccessful();

        Mail::assertNotSent(ReservationAbandoned::class);
    }

    public function test_command_respects_delay_days_config()
    {
        Config::set('resrv-config.enable_abandoned_emails', true);
        Config::set('resrv-config.abandoned_email_delay_days', 3);

        Mail::fake();

        $item = $this->makeStatamicItem();

        // This one expired yesterday - should NOT be picked up with delay=3
        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
            'updated_at' => Carbon::yesterday(),
        ])->withCustomer()->create();

        // This one expired 3 days ago - should be picked up
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
            'updated_at' => Carbon::now()->subDays(3),
        ])->withCustomer()->create();

        $this->artisan('resrv:send-abandoned-emails')
            ->expectsOutputToContain('1 abandoned reservation email(s)')
            ->assertSuccessful();

        Mail::assertSent(ReservationAbandoned::class, function ($mail) use ($reservation) {
            return $mail->hasTo($reservation->customer->email);
        });
    }

    public function test_command_ignores_non_expired_reservations()
    {
        Config::set('resrv-config.enable_abandoned_emails', true);

        Mail::fake();

        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'pending',
            'updated_at' => Carbon::yesterday(),
        ])->withCustomer()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'updated_at' => Carbon::yesterday(),
        ])->withCustomer()->create();

        $this->artisan('resrv:send-abandoned-emails')
            ->expectsOutputToContain('No abandoned reservations found')
            ->assertSuccessful();

        Mail::assertNotSent(ReservationAbandoned::class);
    }
}
