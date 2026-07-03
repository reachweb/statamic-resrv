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
use Reach\StatamicResrv\Support\ActivityLog;
use Statamic\Facades\User;

class DataImportCpController extends Controller
{
    public function index()
    {
        Cache::forget($this->importCacheKey());

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

        $cacheKey = $this->importCacheKey();

        $file = $validated['file'];
        // Scope the stored file per user so concurrent imports can't overwrite each other's upload.
        $path = $file->storeAs('resrv-data-import', $cacheKey.'.csv');
        $path = storage_path('app/'.$path);
        $delimiter = $validated['delimiter'];
        $identifier = $validated['identifier'];
        $collection = $validated['collection'];

        $dataImport = new DataImport($path, $delimiter, $collection, $identifier);

        Cache::put($cacheKey, $dataImport);

        $errors = $dataImport->checkForErrors();

        if ($errors->count() > 0) {
            return $this->renderConfirm($errors->values()->all());
        }

        return $this->renderConfirm([], $dataImport->prepare(true)->all());
    }

    public function store()
    {
        $cacheKey = $this->importCacheKey();

        if (! Cache::has($cacheKey)) {
            return $this->renderConfirm(['No data import object found in cache, please try again']);
        }

        // Capture the actor at dispatch time — the job may run queued, where User::current() is null.
        ProcessDataImport::dispatch($cacheKey, app(ActivityLog::class)->cpActor());

        return Inertia::render('resrv::DataImport/Store', [
            'indexUrl' => cp_route('resrv.dataimport.index'),
        ]);
    }

    // Scope the cache key (and the stored CSV filename) to the authenticated user so concurrent
    // imports by different admins don't clobber each other's file/cache entry.
    protected function importCacheKey(): string
    {
        return 'resrv-data-import-'.(User::current()?->id() ?? 'shared');
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
