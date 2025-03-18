<?php

namespace Reach\StatamicResrv\Livewire;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Reach\StatamicResrv\Facades\ResrvHelper;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Livewire\Forms\AvailabilityCartData;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Livewire\Forms\EnabledExtras;
use Reach\StatamicResrv\Livewire\Forms\EnabledOptions;
use Reach\StatamicResrv\Livewire\Forms\CartReservations;

class CheckoutCart extends Component
{
    use Traits\HandlesCoupons,
        Traits\HandlesExtrasQueries,
        Traits\HandlesOptionsQueries,
        Traits\HandlesPricing,
        Traits\HandlesReservationQueries,
        Traits\HandlesStatamicQueries;

    public string $view = 'checkout-cart';

    #[Session('resrv-cart')]
    public AvailabilityCartData $cart;

    public CartReservations $data;

    #[Locked]
    public string $clientSecret;

    #[Locked]
    public $coupon;

    #[Locked]
    public bool $enableCoupon = true;

    public int $step = 1;

    public bool $enableExtrasStep = true;

    protected $reservationError = false;

    public function mount()
    {
        // Get the reservation or display an error
        try {
            $this->reservation();
        } catch (ReservationException $e) {
            $this->reservationError = $e->getMessage();
        }

        // Redirect back if cart is empty
        if ($this->cart->isEmpty()) {
            return redirect()->back();
        }
        // Handle the first step if extras step is disabled
        if ($this->enableExtrasStep === false) {
            $this->handleFirstStep();
        }

        $this->populateReservations();
        
        $this->coupon = session('resrv_coupon') ?? null;
    }

    #[Computed(persist: true)]
    public function reservation()
    {
        return $this->getReservation()->load('childs');
    }

    #[Computed(persist: true)]
    public function extras(): Collection
    {
        return $this->getExtrasForParentReservation();
    }

    #[Computed(persist: true)]
    public function frontendExtras(): Collection
    {
        return $this->extras->transform(function ($childExtras) {
            return $childExtras->groupBy('category_id')
                ->sortBy('order')
                ->map(function ($items) {
                    return $this->createExtraCategoryObject($items);
                })
                ->reject(function ($category) {
                    return $category->published == false;
                })
                ->sortBy('order')
                ->values();
        });
    }

    public function populateReservations()
    {
        $this->data->setParent($this->reservation);
        $this->reservation->childs->each(function ($child) {
            $this->data->addReservations($child, $this);
        });
    }

    public function render()
    {
        if ($this->reservationError) {
            return view('statamic-resrv::livewire.checkout-error', ['message' => $this->reservationError]);
        }

        return view('statamic-resrv::livewire.'.$this->view, [
            'itemCount' => $this->cart->count(),
        ]);
    }
} 