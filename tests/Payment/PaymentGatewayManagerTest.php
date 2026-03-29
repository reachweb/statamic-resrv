<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class PaymentGatewayManagerTest extends TestCase
{
    use CreatesEntries;

    public $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
    }

    public function test_resolves_single_gateway_from_legacy_config()
    {
        // Default config uses FakePaymentGateway in testing
        $manager = app(PaymentGatewayManager::class);

        $gateway = $manager->gateway();
        $this->assertInstanceOf(FakePaymentGateway::class, $gateway);
        $this->assertEquals('fake', $gateway->name());
    }

    public function test_resolves_multiple_gateways_from_new_config()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake1' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake One',
            ],
            'fake2' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake Two',
            ],
        ]);

        $manager = new PaymentGatewayManager;

        $all = $manager->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('fake1', $all);
        $this->assertArrayHasKey('fake2', $all);
    }

    public function test_gateway_returns_correct_instance_by_name()
    {
        Config::set('resrv-config.payment_gateways', [
            'primary' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Primary',
            ],
            'secondary' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Secondary',
            ],
        ]);

        $manager = new PaymentGatewayManager;

        $this->assertInstanceOf(FakePaymentGateway::class, $manager->gateway('primary'));
        $this->assertInstanceOf(FakePaymentGateway::class, $manager->gateway('secondary'));
    }

    public function test_gateway_returns_default_when_name_is_null()
    {
        $manager = app(PaymentGatewayManager::class);

        $default = $manager->gateway();
        $explicit = $manager->gateway(null);

        $this->assertSame($default, $explicit);
    }

    public function test_for_reservation_resolves_from_payment_gateway_column()
    {
        Config::set('resrv-config.payment_gateways', [
            'gateway_a' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Gateway A',
            ],
            'gateway_b' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Gateway B',
            ],
        ]);

        $manager = new PaymentGatewayManager;

        $reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'payment_gateway' => 'gateway_b',
        ]);

        $gateway = $manager->forReservation($reservation);
        $this->assertInstanceOf(FakePaymentGateway::class, $gateway);
    }

    public function test_for_reservation_falls_back_to_default_for_null_gateway()
    {
        $manager = app(PaymentGatewayManager::class);

        $reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'payment_gateway' => null,
        ]);

        $gateway = $manager->forReservation($reservation);
        $this->assertInstanceOf(FakePaymentGateway::class, $gateway);
    }

    public function test_has_multiple_returns_false_for_single_gateway()
    {
        $manager = app(PaymentGatewayManager::class);

        $this->assertFalse($manager->hasMultiple());
    }

    public function test_has_multiple_returns_true_for_multiple_gateways()
    {
        Config::set('resrv-config.payment_gateways', [
            'one' => [
                'class' => FakePaymentGateway::class,
                'label' => 'One',
            ],
            'two' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Two',
            ],
        ]);

        $manager = new PaymentGatewayManager;

        $this->assertTrue($manager->hasMultiple());
    }

    public function test_available_for_frontend_returns_correct_structure()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
            ],
        ]);

        $manager = new PaymentGatewayManager;

        $available = $manager->availableForFrontend();

        $this->assertCount(2, $available);
        $this->assertEquals('stripe', $available[0]['name']);
        $this->assertEquals('Credit Card', $available[0]['label']);
        $this->assertArrayHasKey('redirects', $available[0]);
        $this->assertEquals('paypal', $available[1]['name']);
        $this->assertEquals('PayPal', $available[1]['label']);
    }

    public function test_label_override_from_config()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Custom Label',
            ],
        ]);

        $manager = new PaymentGatewayManager;

        $available = $manager->availableForFrontend();

        $this->assertEquals('Custom Label', $available[0]['label']);
    }

    public function test_label_falls_back_to_gateway_default()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
            ],
        ]);

        $manager = new PaymentGatewayManager;

        $available = $manager->availableForFrontend();

        $this->assertEquals('Fake Payment', $available[0]['label']);
    }

    public function test_has_returns_true_for_configured_gateway()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
        ]);

        $manager = new PaymentGatewayManager;

        $this->assertTrue($manager->has('stripe'));
    }

    public function test_has_returns_false_for_unknown_gateway()
    {
        $manager = app(PaymentGatewayManager::class);

        $this->assertFalse($manager->has('nonexistent'));
    }

    public function test_throws_for_gateway_with_missing_class()
    {
        Config::set('resrv-config.payment_gateways', [
            'broken' => [
                'label' => 'Broken',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Payment gateway [broken] must have a 'class' that implements PaymentInterface.");

        new PaymentGatewayManager;
    }

    public function test_throws_for_gateway_with_invalid_class()
    {
        Config::set('resrv-config.payment_gateways', [
            'bad' => [
                'class' => \stdClass::class,
                'label' => 'Bad',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Payment gateway [bad] must have a 'class' that implements PaymentInterface.");

        new PaymentGatewayManager;
    }

    public function test_gateway_throws_for_unknown_name()
    {
        $manager = app(PaymentGatewayManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Payment gateway [nonexistent] is not configured.');

        $manager->gateway('nonexistent');
    }

    public function test_label_returns_configured_label()
    {
        Config::set('resrv-config.payment_gateways', [
            'stripe' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Credit Card',
            ],
            'paypal' => [
                'class' => FakePaymentGateway::class,
                'label' => 'PayPal',
            ],
        ]);

        $manager = new PaymentGatewayManager;

        $this->assertEquals('Credit Card', $manager->label('stripe'));
        $this->assertEquals('PayPal', $manager->label('paypal'));
    }

    public function test_label_returns_default_gateway_label_when_null()
    {
        $manager = app(PaymentGatewayManager::class);

        $this->assertEquals('Fake Payment', $manager->label());
    }
}
