<?php

namespace Reach\StatamicResrv\UpdateScripts;

use Reach\StatamicResrv\Support\SettingsMigrator;
use Statamic\UpdateScripts\UpdateScript;

class MigrateConfigToSettings extends UpdateScript
{
    public function shouldUpdate($newVersion, $oldVersion)
    {
        return $this->isUpdatingTo('6.0.0');
    }

    public function update()
    {
        $result = app(SettingsMigrator::class)->migrateFromPublishedConfig();

        if ($result && ($result->hasChanges() || $result->conflicts !== [])) {
            $this->console()->info('Resrv: migrated published config values into the CP settings store.');
            $result->report($this->console());
        }
    }
}
