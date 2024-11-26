<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCategory;

class ExtraCpCategoryController extends Controller
{
    protected $category;

    protected $extra;

    public function __construct(ExtraCategory $category, Extra $extra)
    {
        $this->category = $category;
        $this->extra = $extra;
    }

    public function index()
    {
        $categories = $this->category->with('extras')->orderBy('order', 'asc')->get();

        $uncategorizedExtras = $this->extra->whereNull('category_id')->orderBy('order', 'asc')->get();

        $categories->push([
            'id' => null,
            'name' => 'Uncategorized',
            'slug' => 'uncategorized',
            'description' => null,
            'order' => 9999,
            'published' => true,
            'extras' => $uncategorizedExtras,
        ]);

        return response()->json($categories);
    }

    public function entryIndex($statamic_id)
    {
        $entry = Entry::itemId($statamic_id)->firstOrFail();

        $categories = $this->category
            ->with('extras', 'extras.entries')
            ->get()
            ->transform(function ($category) use ($entry) {
                $category->extras->each(function ($extra) use ($entry) {
                    $extra->setAttribute('enabled', $extra->entries->contains($entry));
                });

                return $category;
            });
        $uncategorizedExtras = $this->extra
            ->whereNull('category_id')
            ->with('entries')
            ->get()
            ->transform(function ($extra) use ($entry) {
                $extra->setAttribute('enabled', $extra->entries->contains($entry));

                return $extra;
            });

        $categories->push([
            'id' => null,
            'name' => 'Uncategorized',
            'slug' => 'uncategorized',
            'description' => null,
            'order' => 9999,
            'published' => true,
            'extras' => $uncategorizedExtras,
        ]);

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string',
            'description' => 'nullable|string',
            'published' => 'required|boolean',
        ]);
        $order = $this->category->max('order') + 1;
        $data['order'] = $order;

        $category = ExtraCategory::create($data);

        return response()->json($category);
    }

    public function update(Request $request, ExtraCategory $category)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string',
            'description' => 'nullable|string',
            'published' => 'required|boolean',
            'order' => 'required|integer',
        ]);

        $category->update($data);

        return response()->json($category);
    }

    public function order(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',
            'order' => 'required|integer',
        ]);

        $category = $this->category->find($data['id'])->changeOrder($data['order']);

        return response(200);
    }

    public function delete(ExtraCategory $category)
    {
        $category->extras()->update(['category_id' => null]);
        $category->delete();

        return response()->json(['success' => true]);
    }
}
