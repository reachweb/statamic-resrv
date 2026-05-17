<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Reach\StatamicResrv\Helpers\DataImport;
use Reach\StatamicResrv\Helpers\ResrvHelper;
use Reach\StatamicResrv\Jobs\ProcessDataImport;

class DataImportCpController extends Controller
{
    public function index()
    {
        Cache::forget('resrv-data-import');

        return Inertia::render('resrv::DataImport/Index', [
            'collections' => ResrvHelper::collectionsWithResrv()->values()->all(),
            'confirmUrl' => cp_route('resrv.dataimport.confirm'),
        ]);
    }

    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'collection' => [
                'required',
                Rule::in(ResrvHelper::collectionsWithResrv()->map(fn ($item) => $item['handle'])),
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
        $path = storage_path('app/'.$path);
        $delimiter = $validated['delimiter'];
        $identifier = $validated['identifier'];
        $collection = $validated['collection'];

        $dataImport = new DataImport($path, $delimiter, $collection, $identifier);

        Cache::put('resrv-data-import', $dataImport);

        $errors = $dataImport->checkForErrors();

        if ($errors->count() > 0) {
            return $this->renderConfirm($errors->values()->all());
        }

        return $this->renderConfirm([], $dataImport->prepare(true)->all());
    }

    public function store()
    {
        if (! Cache::has('resrv-data-import')) {
            return $this->renderConfirm(['No data import object found in cache, please try again']);
        }

        ProcessDataImport::dispatch();

        return Inertia::render('resrv::DataImport/Store', [
            'indexUrl' => cp_route('resrv.dataimport.index'),
        ]);
    }

    protected function renderConfirm(array $errors = [], ?array $sample = null)
    {
        return Inertia::render('resrv::DataImport/Confirm', [
            'errors' => $errors,
            'sample' => $sample,
            'storeUrl' => cp_route('resrv.dataimport.store'),
            'indexUrl' => cp_route('resrv.dataimport.index'),
        ]);
    }
}
