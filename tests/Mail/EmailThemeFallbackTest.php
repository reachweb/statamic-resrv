<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class EmailThemeFallbackTest extends TestCase
{
    use CreatesEntries;

    protected string $logoUrl = 'https://example.com/logo.png';

    protected string $publishedThemePath;

    protected Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishedThemePath = resource_path('views/vendor/statamic-resrv/email/theme');
        $this->removePublishedTheme();

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
        Config::set('resrv-config.logo', $this->logoUrl);
    }

    protected function tearDown(): void
    {
        $this->removePublishedTheme();

        parent::tearDown();
    }

    protected function removePublishedTheme(): void
    {
        $vendorPath = resource_path('views/vendor/statamic-resrv');

        if (File::isDirectory($vendorPath)) {
            File::deleteDirectory($vendorPath);
        }
    }

    protected function renderReservationEmail(): string
    {
        return (new ReservationMade($this->reservation))->render();
    }

    public function test_logo_header_renders_when_no_published_theme_directory_exists()
    {
        $this->assertFalse(File::isDirectory($this->publishedThemePath));

        $html = $this->renderReservationEmail();

        $this->assertStringContainsString('<img src="'.$this->logoUrl.'"', $html);
    }

    public function test_logo_header_renders_when_published_theme_directory_is_empty()
    {
        File::ensureDirectoryExists($this->publishedThemePath);

        $html = $this->renderReservationEmail();

        $this->assertStringContainsString('<img src="'.$this->logoUrl.'"', $html);
    }

    public function test_logo_header_renders_when_published_theme_is_partial()
    {
        File::ensureDirectoryExists($this->publishedThemePath.'/html');
        File::put(
            $this->publishedThemePath.'/html/footer.blade.php',
            '<tr><td class="footer">Custom published footer</td></tr>'
        );

        $html = $this->renderReservationEmail();

        $this->assertStringContainsString('<img src="'.$this->logoUrl.'"', $html);
        $this->assertStringContainsString('Custom published footer', $html);
    }
}
