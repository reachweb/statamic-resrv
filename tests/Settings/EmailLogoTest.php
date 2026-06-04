<?php

namespace Reach\StatamicResrv\Tests\Settings;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class EmailLogoTest extends TestCase
{
    use CreatesEntries;

    public $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
        $this->reservation = Reservation::factory()->withCustomer()->create([
            'item_id' => $entries->first()->id(),
        ]);

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);
        $entry->save();

        Config::set('resrv-config.checkout_completed_entry', $entry->id());
    }

    public static function noLogoValues(): array
    {
        return [
            'boolean false' => [false],
            'null' => [null],
            'empty string' => [''],
        ];
    }

    #[DataProvider('noLogoValues')]
    public function test_business_name_is_rendered_when_no_logo_is_configured(mixed $logo)
    {
        Config::set('resrv-config.logo', $logo);

        $html = (new ReservationConfirmed($this->reservation))->render();

        $this->assertStringNotContainsString('src="false"', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString(config('resrv-config.name'), $html);
    }

    public function test_logo_image_is_rendered_for_a_url()
    {
        Config::set('resrv-config.logo', $logoUrl = 'https://example.com/logo.png');

        $html = (new ReservationConfirmed($this->reservation))->render();

        $this->assertStringContainsString('<img src="'.$logoUrl.'"', $html);
    }
}
