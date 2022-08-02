<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Tests\TestCase;
use Illuminate\Http\Request;

class UtilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function test_availability_search_gets_saved_in_session()
    {       
        $this->travelTo(today()->setHour(11));

        $searchPayload = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->add(1, 'day')->toISOString(),
            'quantity' => 2,
        ];

        $response = $this->post(route('resrv.availability.index'), $searchPayload);

        $response->assertSessionHas([
            'resrv_search' => $searchPayload
        ]);
    }

    public function test_token_method()
    {
        $response = $this->get(route('resrv.utility.token'));
        $response->assertStatus(200)->assertSee(csrf_token());
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
        $response = (new \Reach\StatamicResrv\Http\Middleware\SetResrvSearchByVariables())->handle(
            Request::create('/'.$item->slug.'?date_start='.$s['date_start'].'&date_end='.$s['date_end'], 'GET'),
            fn() => new \Symfony\Component\HttpFoundation\Response()
        );


        // Again to enable assertion (probably there is a better way to do this)
        $response = $this->get('/'.$item->slug.'?date_start='.$s['date_start'].'&date_end='.$s['date_end']);

        $response->assertSessionHas([
            'resrv_search' => $s
        ]);

        // Test normal with duration
        $response = (new \Reach\StatamicResrv\Http\Middleware\SetResrvSearchByVariables())->handle(
            Request::create('/'.$item->slug.'?date_start='.$s['date_start'].'&duration=5&advanced=something', 'GET'),
            fn() => new \Symfony\Component\HttpFoundation\Response()
        );

        $response = $this->get('/'.$item->slug.'?date_start='.$s['date_start'].'&duration=5&advanced=something');

        $s = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->addDays(5)->toDateString(),
            'advanced' => 'something'
        ];
        
        $response->assertSessionHas([
            'resrv_search' => $s
        ]);

    }

}
