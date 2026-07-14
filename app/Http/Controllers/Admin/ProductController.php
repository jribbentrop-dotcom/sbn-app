<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Requests\Admin\ProductStatusRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $query = Product::query();

        if ($search = $request->get('search')) {
            $query->where('title', 'like', "%{$search}%");
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $products = $query->orderBy('title')->paginate(25)->withQueryString();

        return view('admin.products.index', compact('products'));
    }

    public function create(): View
    {
        $product    = new Product;
        $categories = ProductCategory::orderBy('name')->get();
        $isNew      = true;

        return view('admin.products.create', compact('product', 'categories', 'isNew'));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $data = $request->productData();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        $product = Product::create($data);
        $this->syncCategories($product, $request->input('categories', []));

        return redirect()->route('admin.products.edit', $product)
            ->with('success', 'Product created.');
    }

    public function edit(Product $product): View
    {
        $categories        = ProductCategory::orderBy('name')->get();
        $isNew             = false;
        $selectedCategories = $product->categories()->pluck('category_id')->toArray();

        return view('admin.products.edit', compact('product', 'categories', 'isNew', 'selectedCategories'));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->productData());
        $this->syncCategories($product, $request->input('categories', []));

        return redirect()->route('admin.products.edit', $product)
            ->with('success', 'Product saved.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('admin.products.index')
            ->with('success', 'Product deleted.');
    }

    public function updateStatus(ProductStatusRequest $request, Product $product): \Illuminate\Http\JsonResponse
    {
        $product->update(['status' => $request->validated('status')]);
        return response()->json(['success' => true, 'status' => $product->status]);
    }

    private function syncCategories(Product $product, array $categoryIds): void
    {
        $product->categories()->sync(array_filter($categoryIds));
    }
}
