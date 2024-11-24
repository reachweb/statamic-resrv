<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\ExtraCategory;

class ExtraCpCategoryController extends Controller
{
    protected $category;

    public function __construct(ExtraCategory $category)
    {
        $this->category = $category;
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

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string',
            'description' => 'nullable|string',
            'published' => 'required|boolean',
            'order' => 'required|integer',
        ]);

        $category = $this->category->findOrFail($id);
        $category->update($data);

        return response()->json($category);
    }

    public function delete($id)
    {
        $category = $this->category->findOrFail($id);
        $category->extras()->update(['category_id' => null]);
        $category->delete();

        return response()->json(['success' => true]);
    }
}
