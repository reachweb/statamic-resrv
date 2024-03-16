<div class="relative flex items-center gap-x-8">
    <div>
        <x-resrv::datepicker />
    </div>
    @if ($advanced)
    <div>
        <x-resrv::property :properties />
    </div>
    @endif
    
</div>

