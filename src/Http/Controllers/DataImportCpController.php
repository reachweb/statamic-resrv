<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Helpers\ResrvHelper;
use Reach\StatamicResrv\Helpers\DataImport;
use Reach\StatamicResrv\Jobs\ProcessDataImport;

class DataImportCpController extends Controller
{
    public function index()
    {
        Cache::forget('resrv-data-import');
        $collections = ResrvHelper::collectionsWithResrv();
        return view('statamic-resrv::cp.dataimport.index')->with('collections', $collections);
    }

    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'collection' => [
                'required',
                Rule::in(ResrvHelper::collectionsWithResrv()->map(fn($item) => $item['handle'])),
            ],
            'file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain',
            ],
            'identifier' => 'required',
            'delimiter' => 'required',
        ]);


        $file = $validated['file'];
        $path = $file->storeAs('resrv-data-import', 'resrv-data-import.csv');
        $path = storage_path('app/' . $path);
        $delimiter = $validated['delimiter'];
        $identifier = $validated['identifier'];
        $collection = $validated['collection'];

        $dataImport = new DataImport($path, $delimiter, $collection, $identifier);

        Cache::put('resrv-data-import', $dataImport);
        
        $errors = $dataImport->checkForErrors();

        $sample = $dataImport->prepare(true)->all();

        return view('statamic-resrv::cp.dataimport.confirm')
            ->with('errors', $errors)
            ->with('sample', $sample);
    }

    public function store()
    {
        if (! Cache::has('resrv-data-import')) {
            return view('statamic-resrv::cp.dataimport.confirm')
                ->with('errors', collect(['No data import object found in cache, please try again']));
        }
        
        ProcessDataImport::dispatch();

        return view('statamic-resrv::cp.dataimport.store');

    }


}
