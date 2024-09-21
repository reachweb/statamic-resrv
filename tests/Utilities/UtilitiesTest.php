<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Tests\TestCase;

class UtilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
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
        $response = (new \Reach\StatamicResrv\Http\Middleware\SetResrvSearchByVariables)->handle(
            Request::create('/'.$item->slug.'?date_start='.$s['date_start'].'&date_end='.$s['date_end'], 'GET'),
            fn () => new \Symfony\Component\HttpFoundation\Response
        );

        // Again to enable assertion (probably there is a better way to do this)
        $response = $this->get('/'.$item->slug.'?date_start='.$s['date_start'].'&date_end='.$s['date_end']);

        $response->assertSessionHas([
            'resrv_search' => $s,
        ]);

        // Test normal with duration
        $response = (new \Reach\StatamicResrv\Http\Middleware\SetResrvSearchByVariables)->handle(
            Request::create('/'.$item->slug.'?date_start='.$s['date_start'].'&duration=5&advanced=something', 'GET'),
            fn () => new \Symfony\Component\HttpFoundation\Response
        );

        $response = $this->get('/'.$item->slug.'?date_start='.$s['date_start'].'&duration=5&advanced=something');

        $s = [
            'date_start' => today()->setHour(12)->toISOString(),
            'date_end' => today()->setHour(12)->addDays(5)->toDateString(),
            'advanced' => 'something',
        ];

        $response->assertSessionHas([
            'resrv_search' => $s,
        ]);
    }
}
