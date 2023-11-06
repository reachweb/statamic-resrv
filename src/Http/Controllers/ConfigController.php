<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Edalzell\Forma\ConfigController as BaseConfigController;
use Edalzell\Forma\Events\ConfigSaved;
use Edalzell\Forma\Forma;
use Illuminate\Http\Request;
use Statamic\Extend\Addon;
use Statamic\Facades\Blueprint as BlueprintAPI;
use Statamic\Facades\Path;
use Statamic\Facades\YAML;
use Statamic\Fields\Blueprint;
use Stillat\Proteus\Support\Facades\ConfigWriter;

class ConfigController extends BaseConfigController
{
    public function edit(Request $request)
    {
        $slug = $request->segment(2);

        $addon = Forma::findBySlug($slug);

        $blueprint = $this->getBlueprint($slug);

        $fields = $blueprint
            ->fields()
            ->addValues($this->preProcess('resrv-config'))
            ->preProcess();

        return view('forma::edit', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $fields->meta(),
            'route' => cp_route("{$slug}.config.update", ['handle' => $slug]),
            'title' => $this->cpTitle($addon),
            'values' => $fields->values(),
        ]);
    }

    public function update(Request $request)
    {
        $slug = $request->segment(2);

        $blueprint = $this->getBlueprint($slug);

        // Get a Fields object, and populate it with the submitted values.
        $fields = $blueprint->fields()->addValues($request->all());

        // Perform validation. Like Laravel's standard validation, if it fails,
        // a 422 response will be sent back with all the validation errors.
        $fields->validate();

        $data = $this->postProcess($fields->process()->values()->toArray());

        ConfigWriter::writeMany('resrv-config', $data);

        ConfigSaved::dispatch($data);
    }

    private function getBlueprint(string $slug): Blueprint
    {
        $addon = Forma::findBySlug($slug);

        $path = Path::assemble($addon->directory(), 'resources', 'blueprints', 'config.yaml');

        return BlueprintAPI::makeFromFields(YAML::file($path)->parse());
    }

    protected function postProcess(array $values): array
    {
        return collect($values)->reject(function ($value) {
            return is_null($value);
        })->transform(function ($value) {
            if ($value === 'true') {
                return true;
            }
            if ($value === 'false') {
                return false;
            }

            return $value;
        })->toArray();
    }

    private function cpTitle(Addon $addon)
    {
        return __(':name Settings', ['name' => $addon->name()]);
    }
}
