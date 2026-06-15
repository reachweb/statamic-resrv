<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;

class OptionCpController extends Controller
{
    protected $option;

    protected $value;

    public function __construct(Option $option, OptionValue $value)
    {
        $this->option = $option;
        $this->value = $value;
    }

    public function entryIndex($statamic_id)
    {
        $disabled = OptionValue::disabledIdsForEntry($statamic_id);

        $options = $this->option->entry($statamic_id)->with('values')->get();

        // Flag each value with whether it is currently disabled for this entry so the per-entry editor
        // can reflect and toggle the sparse resrv_option_value_entries exception rows.
        $options->each(function ($option) use ($disabled) {
            $option->values->each(function ($value) use ($disabled) {
                $value->disabled_for_entry = in_array($value->id, $disabled, true);
            });
        });

        return response()->json($options);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'slug' => 'required',
            'description' => 'nullable',
            'item_id' => 'nullable|string',
            'collection' => 'nullable|string',
            'apply_to_all' => 'nullable|boolean',
            'entries' => 'nullable|array',
            'entries.*' => 'string',
            'required' => 'required|boolean',
            'published' => 'required|boolean',
        ]);

        [$data, $entries] = $this->resolveEntryAttachment($data);

        $data['order'] = $this->option->max('order') + 1;

        $option = $this->option->create($data);

        if ($entries !== null) {
            $option->entries()->sync($entries);
        }

        return response()->json(['id' => $option->id]);
    }

    /**
     * Normalize the entry-attachment payload. Supports the new model (collection + apply_to_all +
     * entries[]) and the legacy single-entry shape (item_id), which is translated into a derived
     * collection plus a one-entry pivot so the existing CP UI keeps working during the migration.
     *
     * @return array{0: array<string, mixed>, 1: ?array<int, string>}
     */
    protected function resolveEntryAttachment(array $data): array
    {
        $itemId = $data['item_id'] ?? null;

        $entries = array_key_exists('entries', $data)
            ? $data['entries']
            : ($itemId ? [$itemId] : null);

        if ($itemId && empty($data['collection'])) {
            $data['collection'] = Entry::collectionForItem($itemId);
        }

        $data['apply_to_all'] = (bool) ($data['apply_to_all'] ?? false);

        unset($data['item_id'], $data['entries']);

        return [$data, $entries];
    }

    public function createValue(Request $request, $id)
    {
        $option = $this->option->findOrFail($id);

        $data = $request->validate([
            'name' => 'required',
            'price' => 'required|numeric',
            'price_type' => 'required|in:free,fixed,perday',
            'published' => 'required|boolean',
        ]);

        $order = $this->value->where('option_id', $id)->max('order') + 1;
        $data['order'] = $order;
        $data['option_id'] = $option->id;

        $value = $this->value->create($data);

        return response()->json(['id' => $value->id]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'name' => 'required',
            'slug' => 'required',
            'description' => 'nullable',
            'item_id' => 'nullable|string',
            'collection' => 'nullable|string',
            'apply_to_all' => 'nullable|boolean',
            'entries' => 'nullable|array',
            'entries.*' => 'string',
            'order' => 'required|integer',
            'required' => 'required|boolean',
            'published' => 'required|boolean',
        ]);

        $option = $this->option->findOrFail($data['id']);

        [$data, $entries] = $this->resolveEntryAttachment($data);

        $option->update($data);

        if ($entries !== null) {
            $option->entries()->sync($entries);
        }

        return response()->json(['id' => $option->id]);
    }

    public function updateValue(Request $request, $id)
    {
        $option = $this->option->findOrFail($id);

        $data = $request->validate([
            'id' => 'required|integer',
            'name' => 'required',
            'price' => 'required|numeric',
            'price_type' => 'required|in:free,fixed,perday',
            'order' => 'required|integer',
            'published' => 'required|boolean',
        ]);

        $data['option_id'] = $option->id;

        $value = $this->value->findOrFail($data['id'])->update($data);

        return response()->json(['id' => $data['id']]);
    }

    /**
     * Toggle whether a single option value is disabled for a specific entry. Backed by the sparse
     * resrv_option_value_entries exception pivot: a row means disabled, no row means enabled.
     */
    public function toggleDisableForEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'option_value_id' => 'required|integer',
            'statamic_id' => 'required|string',
            'disabled' => 'required|boolean',
        ]);

        $value = $this->value->findOrFail($data['option_value_id']);

        if ($data['disabled']) {
            // syncWithoutDetaching is idempotent against the (option_value_id, statamic_id) unique
            // constraint, so toggling on twice does not throw.
            $value->disabledEntries()->syncWithoutDetaching([$data['statamic_id']]);
        } else {
            $value->disabledEntries()->detach($data['statamic_id']);
        }

        return response()->json(['disabled' => $data['disabled']]);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $option = $this->option->destroy($data['id']);

        $this->value->where('option_id', $data['id'])->delete();

        return response(200);
    }

    public function deleteValue(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $value = $this->value->destroy($data['id']);

        return response(200);
    }

    public function order(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',
            'order' => 'required|integer',
        ]);

        $this->option->findOrFail($data['id'])->changeOrder($data['order']);

        return response(200);
    }

    public function orderValue(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',
            'order' => 'required|integer',
        ]);

        $this->value->findOrFail($data['id'])->changeOrder($data['order']);

        return response(200);
    }
}
