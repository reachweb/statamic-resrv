@use(Carbon\Carbon)

<div>
    <div class="mb-6 flex items-center justify-between">
        <h2 class="text-xl font-bold">{{ trans('statamic-resrv::frontend.reservations') }} ({{ $itemCount }})</h2>
    </div>

    @if(session()->has('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            {{ session('error') }}
        </div>
    @endif

    @if($cart->isEmpty())
        <div class="bg-gray-100 p-8 rounded-lg text-center">
            <p class="text-gray-600">{{ trans('statamic-resrv::frontend.reservationsEmpty') }}</p>
        </div>
    @else
        <div class="mb-6 space-y-6">
            @foreach($cart->items as $id => $data)
                <div wire:key="{{ $data }}" class="border rounded-lg p-4 {{ $data->valid ? 'bg-white' : 'bg-red-50 border-red-200' }}">
                    <livewire:cart-item-results wire:key="{{ $data }}" :$data />
                </div>
            @endforeach
        </div>

        <div class="flex justify-between items-center mt-8 p-4 bg-gray-100 rounded-lg">
            <div>
                <p class="text-lg font-bold">
                    {{ trans('statamic-resrv::frontend.total') }}: {{ config('resrv-config.currency_symbol') . $this->calculateCartTotalPrice() }}
                </p>
                <p class="text-sm text-gray-600">
                    {{ trans('statamic-resrv::frontend.payableNow') }}: {{ config('resrv-config.currency_symbol') . $this->calculateCartPaymentAmount() }}
                </p>
            </div>
            <button 
                wire:click="checkout" 
                class="px-6 py-3 bg-blue-700 text-white rounded-lg hover:bg-blue-800 disabled:bg-gray-400"
                {{ ! $allValid ? 'disabled' : '' }}
            >
                {{ trans('statamic-resrv::frontend.bookNow') }}
            </button>
        </div>
    @endif

    <div class="absolute inset-0 bg-white/50 flex items-center justify-center" wire:loading.delay>
        <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-700"></div>
    </div>
</div>
