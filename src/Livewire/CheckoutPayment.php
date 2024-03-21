<?php

namespace Reach\StatamicResrv\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;

class CheckoutPayment extends Component
{
    use Traits\HandlesStatamicQueries;

    public string $view = 'checkout-payment';

    #[Locked]
    public string $clientSecret;

    #[Locked]
    public float $amount;   
    
    #[Locked]
    public string $checkoutCompletedUrl;

    public function mount(): void
    {
        $this->checkoutCompletedUrl = $this->getCheckoutCompleteEntry()->absoluteUrl();
    }
    
    public function render()
    {
        return view('statamic-resrv::livewire.'.$this->view);
    }
}
