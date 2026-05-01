<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Reach\StatamicResrv\Http\Middleware\SetResrvSearchByVariables;
use Reach\StatamicResrv\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class UtilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_availability_search_can_be_set_by_url()
    {
        $item = $this->makeStatamicItem();
        $this->withStandardFakeViews();

        $s = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
        ];

        // Test normal
        $response = (new SetResrvSearchByVariables)->handle(
            Request::create('/'.$item->slug.'?date_start='.$s['date_start'].'&date_end='.$s['date_end'], 'GET'),
            fn () => new Response
        );

        // Again to enable assertion (probably there is a better way to do this)
        $response = $this->get('/'.$item->slug.'?date_start='.$s['date_start'].'&date_end='.$s['date_end']);

        $response->assertSessionHas([
            'resrv_search' => $s,
        ]);

        // Test normal with duration
        $response = (new SetResrvSearchByVariables)->handle(
            Request::create('/'.$item->slug.'?date_start='.$s['date_start'].'&duration=5&rate_id=something', 'GET'),
            fn () => new Response
        );

        $response = $this->get('/'.$item->slug.'?date_start='.$s['date_start'].'&duration=5&rate_id=something');

        $s = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->addDays(5)->toDateString(),
            'rate_id' => 'something',
        ];

        $response->assertSessionHas([
            'resrv_search' => $s,
        ]);
    }
}
