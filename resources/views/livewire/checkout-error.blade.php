<div>
    <div class="flex flex-col my-4 md:my-6 p-4 bg-red-50 border border-red-300 rounded">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.somethingWentWrong') }}</dt>
        <dd class="mb-1 text-gray-700 lg:text-lg ">
            <div>{{ $message }}</div>
        </dd>
    </div>
    <a class="flex items-center lg:text-lg font-medium bg-gray-100 border border-gray-200 rounded p-4" href="{{ url()->previous() }}">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
        </svg>
        {{ trans('statamic-resrv::frontend.returnToThePreviousPage') }}
    </a>
</div>

