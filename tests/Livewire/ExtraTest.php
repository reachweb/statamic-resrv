<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\Extra;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class ExtraTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
    }

    /** @test */
    public function renders_successfully()
    {
        $extra = ResrvExtra::factory()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);

        // We need to do this because of the way we get the extra data in the Livewire component
        $extra = json_decode($extra->toJson());

        Livewire::test(Extra::class, ['extra' => $extra])
            ->assertViewIs('statamic-resrv::livewire.extra')
            ->assertViewHas('extra', $extra);
    }

    /** @test */
    public function it_changes_quantity_when_selected()
    {
        $extra = ResrvExtra::factory()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);

        // We need to do this because of the way we get the extra data in the Livewire component
        $extra = json_decode($extra->toJson());

        Livewire::test(Extra::class, ['extra' => $extra])
            ->set('selected', true)
            ->assertSet('quantity', 1)
            ->set('selected', false)
            ->assertSet('quantity', 0);
    }

    /** @test */
    public function it_dispatches_the_event_when_selected_and_unselected()
    {
        $extra = ResrvExtra::factory()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);

        // We need to do this because of the way we get the extra data in the Livewire component
        $extra = json_decode($extra->toJson());

        Livewire::test(Extra::class, ['extra' => $extra])
            ->set('selected', true)
            ->assertSet('quantity', 1)
            ->assertSet('selected', true)
            ->assertDispatched('extra-changed', [
                'id' => $extra->id,
                'price' => $extra->price,
                'quantity' => 1,
            ])
            ->set('selected', false)
            ->assertDispatched('extra-changed', [
                'id' => $extra->id,
                'price' => $extra->price,
                'quantity' => 0,
            ]);
    }

    /** @test */
    public function it_dispatches_the_event_when_quantity_changes()
    {
        $extra = ResrvExtra::factory()->create();

        DB::table('resrv_statamicentry_extra')->insert([
            'statamicentry_id' => $this->entries->first()->id,
            'extra_id' => $extra->id,
        ]);

        // We need to do this because of the way we get the extra data in the Livewire component
        $extra = json_decode($extra->toJson());

        Livewire::test(Extra::class, ['extra' => $extra])
            ->set('selected', true)
            ->assertSet('quantity', 1)
            ->set('quantity', 2)
            ->assertDispatched('extra-changed', [
                'id' => $extra->id,
                'price' => $extra->price,
                'quantity' => 2,
            ]);
    }

    // TODO: Add tests with a reservation to check if we get the right prices
}
