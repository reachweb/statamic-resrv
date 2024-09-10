<?php

namespace Reach\StatamicResrv\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

class InstallResrv extends Command
{
    use RunsInPlease;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resrv:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Statamic Resrv';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->publishConfigurationFile();
        if ($this->confirm('Do you want to publish the checkout form? (needed for Resrv to work correctly)', true)) {
            $this->publishCheckoutForm();
        }
        if ($this->confirm('Do you want to publish the Livewire views? (recommended)', true)) {
            $this->publishCheckoutViews();
        }
        if ($this->confirm('Do you want to publish the language files? (needed only if you wish to edit them)')) {
            $this->publishLanguageFiles();
        }
        if ($this->confirm('Do you want to publish the email templates? (needed only if you wish to edit them)')) {
            $this->publishEmailTemplates();
        }
        $this->info('Installation finished. Go get some reservations!');
    }

    protected function publishConfigurationFile()
    {
        $this->info('Publishing configuration file');

        $this->callSilent('vendor:publish', [
            '--tag' => 'resrv-config',
        ]);

        return $this;
    }

    protected function publishCheckoutForm()
    {
        $this->info('Publishing checkout form');

        $this->callSilent('vendor:publish', [
            '--tag' => 'resrv-forms',
        ]);

        $this->info('Publishing checkout form blueprint');

        $this->callSilent('vendor:publish', [
            '--tag' => 'resrv-blueprints',
        ]);

        return $this;
    }

    protected function publishCheckoutViews()
    {
        $this->info('Publishing Livewire views');

        $this->callSilent('vendor:publish', [
            '--tag' => 'resrv-checkout-views',
        ]);

        return $this;
    }

    protected function publishEmailTemplates()
    {
        $this->info('Publishing email templates');

        $this->callSilent('vendor:publish', [
            '--tag' => 'resrv-emails',
        ]);

        return $this;
    }

    protected function publishLanguageFiles()
    {
        $this->info('Publishing language files');

        $this->callSilent('vendor:publish', [
            '--tag' => 'resrv-language',
        ]);

        return $this;
    }
}
