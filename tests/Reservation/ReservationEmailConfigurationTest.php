<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Exceptions\CheckoutFormNotFoundException;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Mail\ReservationRefunded;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\CheckoutFormResolver;
use Reach\StatamicResrv\Support\ReservationEmailDispatcher;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationEmailConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_checkout_mapping_wins_over_other_candidates()
    {
        $item = $this->makeStatamicItem();

        Config::set('resrv-config.checkout_forms_default', 'missing-default-form');
        Config::set('resrv-config.checkout_forms_collections', [
            ['collection' => 'pages', 'form' => 'missing-collection-form'],
        ]);
        Config::set('resrv-config.checkout_forms_entries', [
            ['entry' => $item->id(), 'form' => 'checkout'],
        ]);

        $resolver = app(CheckoutFormResolver::class);

        $this->assertEquals('checkout', $resolver->resolveForEntryId($item->id())->handle());
    }

    public function test_collection_checkout_mapping_is_used_when_entry_mapping_is_invalid()
    {
        $item = $this->makeStatamicItem();

        Config::set('resrv-config.checkout_forms_default', 'missing-default-form');
        Config::set('resrv-config.checkout_forms_collections', [
            ['collection' => 'pages', 'form' => 'checkout'],
        ]);
        Config::set('resrv-config.checkout_forms_entries', [
            ['entry' => $item->id(), 'form' => 'missing-entry-form'],
        ]);

        $resolver = app(CheckoutFormResolver::class);

        $this->assertEquals('checkout', $resolver->resolveForEntryId($item->id())->handle());
    }

    public function test_resolver_throws_when_no_valid_checkout_form_exists()
    {
        $item = $this->makeStatamicItem();

        Config::set('resrv-config.checkout_forms_default', 'missing-default-form');
        Config::set('resrv-config.checkout_forms_collections', [
            ['collection' => 'pages', 'form' => 'missing-collection-form'],
        ]);
        Config::set('resrv-config.checkout_forms_entries', [
            ['entry' => $item->id(), 'form' => 'missing-entry-form'],
        ]);

        $this->expectException(CheckoutFormNotFoundException::class);

        app(CheckoutFormResolver::class)->resolveForEntryId($item->id());
    }

    public function test_per_form_email_override_replaces_global_email_settings()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'subject' => 'Global Subject',
                'recipients' => 'customer',
            ],
        ]);
        Config::set('resrv-config.reservation_emails_forms', [
            [
                'form' => 'checkout',
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'subject' => 'Form Subject',
                'recipients' => 'custom@example.com',
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) use ($reservation) {
            return $mail->hasTo('custom@example.com')
                && ! $mail->hasTo($reservation->customer->email)
                && $mail->subject === 'Form Subject';
        });
    }

    public function test_event_can_be_disabled_through_email_config()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'enabled' => false,
                'recipients' => 'customer',
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertNotSent(ReservationConfirmed::class);
    }

    public function test_from_override_is_applied_to_mailable()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'recipients' => 'customer',
                'from_address' => 'bookings@example.com',
                'from_name' => 'Reservations Team',
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) {
            return $mail->hasFrom('bookings@example.com', 'Reservations Team');
        });
    }

    public function test_markdown_override_is_applied_to_mailable()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'recipients' => 'customer',
                'markdown' => 'statamic-resrv::email.reservations.refunded',
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) {
            return str_contains($mail->render(), 'Your reservation has been refunded.');
        });
    }

    public function test_invalid_recipients_are_filtered_and_send_is_skipped()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'recipients' => 'not-an-email,also-invalid',
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertNotSent(ReservationConfirmed::class);
    }

    public function test_custom_recipients_with_valid_emails_are_used()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'recipients' => 'test@example.com,custom:custom@example.com',
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertSent(ReservationConfirmed::class, 2);
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) {
            return $mail->hasTo('test@example.com')
                && ! $mail->hasTo('custom@example.com');
        });
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) {
            return $mail->hasTo('custom@example.com')
                && ! $mail->hasTo('test@example.com');
        });
    }

    public function test_admin_made_default_recipients_include_admins_and_affiliate()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        $affiliate = Affiliate::factory()->create();
        $reservation->affiliate()->attach($affiliate->id, ['fee' => $affiliate->fee]);

        Config::set('resrv-config.admin_email', 'admin1@example.com,admin2@example.com');
        Config::set('resrv-config.enable_affiliates', true);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::AdminMade,
            new ReservationMade($reservation),
        );

        Mail::assertSent(ReservationMade::class, 3);
        Mail::assertSent(ReservationMade::class, function (ReservationMade $mail) use ($affiliate) {
            return $mail->hasTo('admin1@example.com')
                && ! $mail->hasTo('admin2@example.com')
                && ! $mail->hasTo($affiliate->email);
        });
        Mail::assertSent(ReservationMade::class, function (ReservationMade $mail) use ($affiliate) {
            return $mail->hasTo('admin2@example.com')
                && ! $mail->hasTo('admin1@example.com')
                && ! $mail->hasTo($affiliate->email);
        });
        Mail::assertSent(ReservationMade::class, function (ReservationMade $mail) use ($affiliate) {
            return $mail->hasTo($affiliate->email)
                && ! $mail->hasTo('admin1@example.com')
                && ! $mail->hasTo('admin2@example.com');
        });
    }

    public function test_refunded_default_recipients_include_customer_and_admins()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.admin_email', 'admin1@example.com,admin2@example.com');

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerRefunded,
            new ReservationRefunded($reservation),
        );

        Mail::assertSent(ReservationRefunded::class, 3);
        Mail::assertSent(ReservationRefunded::class, function (ReservationRefunded $mail) use ($reservation) {
            return $mail->hasTo($reservation->customer->email)
                && ! $mail->hasTo('admin1@example.com')
                && ! $mail->hasTo('admin2@example.com');
        });
        Mail::assertSent(ReservationRefunded::class, function (ReservationRefunded $mail) use ($reservation) {
            return $mail->hasTo('admin1@example.com')
                && ! $mail->hasTo($reservation->customer->email)
                && ! $mail->hasTo('admin2@example.com');
        });
        Mail::assertSent(ReservationRefunded::class, function (ReservationRefunded $mail) use ($reservation) {
            return $mail->hasTo('admin2@example.com')
                && ! $mail->hasTo($reservation->customer->email)
                && ! $mail->hasTo('admin1@example.com');
        });
    }

    public function test_partial_override_preserves_default_recipients()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.admin_email', 'admin1@example.com');
        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerRefunded->value,
                'subject' => 'Refund processed',
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerRefunded,
            new ReservationRefunded($reservation),
        );

        Mail::assertSent(ReservationRefunded::class, 2);
        Mail::assertSent(ReservationRefunded::class, function (ReservationRefunded $mail) use ($reservation) {
            return $mail->hasTo($reservation->customer->email)
                && ! $mail->hasTo('admin1@example.com')
                && $mail->subject === 'Refund processed';
        });
        Mail::assertSent(ReservationRefunded::class, function (ReservationRefunded $mail) use ($reservation) {
            return $mail->hasTo('admin1@example.com')
                && ! $mail->hasTo($reservation->customer->email)
                && $mail->subject === 'Refund processed';
        });
    }

    public function test_friendly_recipient_fields_are_supported()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.admin_email', 'admin1@example.com');
        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'recipient_sources' => ['admins'],
                'recipient_emails' => "one@example.com\ntwo@example.com",
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertSent(ReservationConfirmed::class, 3);
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) {
            return $mail->hasTo('admin1@example.com');
        });
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) {
            return $mail->hasTo('one@example.com');
        });
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) {
            return $mail->hasTo('two@example.com');
        });
    }

    public function test_empty_friendly_recipient_fields_preserve_default_recipients()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'subject' => 'Updated subject',
                'recipient_sources' => [],
                'recipient_emails' => '',
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertSent(ReservationConfirmed::class, 1);
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) use ($reservation) {
            return $mail->hasTo($reservation->customer->email)
                && $mail->subject === 'Updated subject';
        });
    }

    public function test_case_insensitive_recipient_tokens_are_supported()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.admin_email', 'admin1@example.com');
        Config::set('resrv-config.reservation_emails_global', [
            [
                'event' => ReservationEmailEvent::CustomerConfirmed->value,
                'recipients' => 'Admins,CUSTOM:Custom@Example.com',
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertSent(ReservationConfirmed::class, 2);
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) {
            return $mail->hasTo('admin1@example.com')
                && ! $mail->hasTo('custom@example.com');
        });
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) {
            return $mail->hasTo('custom@example.com')
                && ! $mail->hasTo('admin1@example.com');
        });
    }

    public function test_short_associative_recipient_config_is_supported()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $item->id(),
        ]);

        Config::set('resrv-config.admin_email', 'admin1@example.com');
        Config::set('resrv-config.reservation_emails.global.customer_confirmed', [
            'recipients' => [
                'customer' => true,
                'admins' => true,
            ],
        ]);

        app(ReservationEmailDispatcher::class)->send(
            $reservation,
            ReservationEmailEvent::CustomerConfirmed,
            new ReservationConfirmed($reservation),
        );

        Mail::assertSent(ReservationConfirmed::class, 2);
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) use ($reservation) {
            return $mail->hasTo($reservation->customer->email)
                && ! $mail->hasTo('admin1@example.com');
        });
        Mail::assertSent(ReservationConfirmed::class, function (ReservationConfirmed $mail) use ($reservation) {
            return $mail->hasTo('admin1@example.com')
                && ! $mail->hasTo($reservation->customer->email);
        });
    }
}
