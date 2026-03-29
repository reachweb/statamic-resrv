<?php

namespace Reach\StatamicResrv\Tests;

use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk as BasePreventsSavingStacheItemsToDisk;

trait PreventSavingStacheItemsToDisk
{
    use BasePreventsSavingStacheItemsToDisk;

    protected function deleteFakeStacheDirectory(): void
    {
        app('files')->deleteDirectory($this->fakeStacheDirectory);

        if (! is_dir($this->fakeStacheDirectory)) {
            mkdir($this->fakeStacheDirectory);
        }

        touch($this->fakeStacheDirectory.'/.gitkeep');
    }
}
