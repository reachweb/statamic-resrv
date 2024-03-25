@props(['step', 'enableExtrasStep'])

<ol class="flex items-center w-full font-medium text-center text-gray-500 text-base">
    @if ($enableExtrasStep)
    <li 
        wire:click="{{ $step !== 1 ? 'goToStep(1)' : '' }}" 
        @class([
            "flex md:w-full items-center sm:after:content-[''] after:w-full after:h-1 after:border-b after:border-gray-200 after:border-1 after:hidden sm:after:inline-block after:mx-6 transition-colors duration-300", 
            "text-blue-600" => $step === 1,
            "cursor-pointer" => $step !== 1,
            ])
    >
        <span class="flex basis-full items-center after:content-['/'] sm:after:hidden after:mx-2 after:text-gray-200">
            @if ($step === 1)            
            <svg class="w-3.5 h-3.5 sm:w-6 sm:h-6 me-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 8.207-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L9 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414Z"/>
            </svg>
            @else
            <div class="flex items-center justify-center rounded-full w-7 h-7 p-1 bg-gray-200 me-2">
                <span class="font-bold text-sm">1</span>
            </div>
            @endif
            {{ trans('statamic-resrv::frontend.extrasAndOptions') }}
        </span>
    </li>
    @endif
    <li 
        wire:click="{{ $step > 2 ? 'goToStep(2)' : '' }}" 
        @class([
            "flex md:w-full items-center sm:after:content-[''] after:w-full after:h-1 after:border-b after:border-gray-200 after:border-1 after:hidden sm:after:inline-block after:mx-6 transition-colors duration-300", 
            "text-blue-600" => $step === 2,
            "cursor-pointer" => $step > 2,
            ])
    >
        <span class="flex basis-full items-center after:content-['/'] sm:after:hidden after:mx-2 after:text-gray-200">
            @if ($step === 2)
            <svg class="w-3.5 h-3.5 sm:w-6 sm:h-6 me-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 8.207-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L9 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414Z"/>
            </svg>
            @else
            <div class="flex items-center justify-center rounded-full w-7 h-7 p-1 bg-gray-200 me-2">
                <span class="font-bold text-sm">2</span>
            </div>
            @endif
            {{ trans('statamic-resrv::frontend.customerInfo') }}
        </span>
    </li>
    <li @class(["flex items-center transition-colors duration-300", "text-blue-600" => $step === 3])>
        <span class="flex basis-full items-center">
            @if ($step === 3)
            <svg class="w-3.5 h-3.5 sm:w-6 sm:h-6 me-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 8.207-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L9 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414Z"/>
            </svg>
            @else
            <div class="flex items-center justify-center rounded-full w-7 h-7 p-1 bg-gray-200 me-2">
                <span class="font-bold text-sm">3</span>
            </div>
            @endif
            {{ trans('statamic-resrv::frontend.payment') }}
        </span>
    </li>
</ol>
