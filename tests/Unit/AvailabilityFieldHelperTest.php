<?php

namespace Reach\StatamicResrv\Tests\Unit;

use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Helpers\ResrvHelper;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Fields\Blueprint as BlueprintInstance;

class AvailabilityFieldHelperTest extends TestCase
{
    private BlueprintInstance $withoutField;

    private BlueprintInstance $withField;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('pages')->routes('/{slug}')->save();

        $this->withoutField = $this->makeBlueprint('pages', [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ]);

        $this->withField = $this->makeBlueprint('pages_with_reservation', [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'resrv_availability', 'field' => ['type' => 'resrv_availability']],
        ]);
    }

    public function test_blueprint_has_availability_field_is_scoped_per_blueprint_in_same_namespace()
    {
        // Priming the cache with a field-less blueprint must not mask the
        // availability field on a sibling blueprint in the same namespace.
        $this->assertFalse(AvailabilityField::blueprintHasAvailabilityField($this->withoutField));
        $this->assertTrue(AvailabilityField::blueprintHasAvailabilityField($this->withField));
    }

    public function test_collections_with_resrv_includes_collection_when_only_one_blueprint_has_the_field()
    {
        $handles = ResrvHelper::collectionsWithResrv()->pluck('handle')->all();

        $this->assertContains('pages', $handles);
    }

    private function makeBlueprint(string $handle, array $fields): BlueprintInstance
    {
        return tap(
            Blueprint::make()
                ->setContents(['sections' => ['main' => ['fields' => $fields]]])
                ->setHandle($handle)
                ->setNamespace('collections.pages')
        )->save();
    }
}
