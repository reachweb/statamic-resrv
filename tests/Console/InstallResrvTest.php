<?php

namespace Reach\StatamicResrv\Tests\Console;

use Reach\StatamicResrv\Tests\TestCase;

class InstallResrvTest extends TestCase
{
    protected function tearDown(): void
    {
        if (is_file($path = config_path('resrv-config.php'))) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function test_config_file_is_not_published_by_default()
    {
        $this->artisan('resrv:install')
            ->expectsConfirmation('Publish the developer configuration file (payment gateways & Stripe keys)? Most sites don\'t need this.', 'no')
            ->expectsConfirmation('Do you want to publish the checkout form? (needed for Resrv to work correctly)', 'no')
            ->expectsConfirmation('Do you want to publish the Livewire views? (recommended)', 'no')
            ->expectsConfirmation('Do you want to publish the language files? (needed only if you wish to edit them)', 'no')
            ->expectsConfirmation('Do you want to publish the email templates? (needed only if you wish to edit them)', 'no')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist(config_path('resrv-config.php'));
    }

    public function test_config_file_is_published_when_confirmed()
    {
        $this->artisan('resrv:install')
            ->expectsConfirmation('Publish the developer configuration file (payment gateways & Stripe keys)? Most sites don\'t need this.', 'yes')
            ->expectsConfirmation('Do you want to publish the checkout form? (needed for Resrv to work correctly)', 'no')
            ->expectsConfirmation('Do you want to publish the Livewire views? (recommended)', 'no')
            ->expectsConfirmation('Do you want to publish the language files? (needed only if you wish to edit them)', 'no')
            ->expectsConfirmation('Do you want to publish the email templates? (needed only if you wish to edit them)', 'no')
            ->assertExitCode(0);

        $this->assertFileExists(config_path('resrv-config.php'));

        $published = require config_path('resrv-config.php');

        $this->assertArrayHasKey('payment_gateway', $published);
        $this->assertArrayNotHasKey('maximum_quantity', $published);
    }
}
