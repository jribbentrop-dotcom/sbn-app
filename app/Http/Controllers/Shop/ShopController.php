<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShopController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::published()->with(['categories', 'tags']);

        // Filter by category
        if ($request->has('category')) {
            $query->byCategory($request->input('category'));
        }

        // Search
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sort = $request->input('sort', 'title');
        $direction = $request->input('direction', 'asc');

        match ($sort) {
            'price' => $query->orderBy('price_cents', $direction),
            'date' => $query->orderBy('published_at', $direction),
            default => $query->orderBy('title', $direction),
        };

        $products = $query->paginate(20)->withQueryString();

        // Transform for Inertia
        $products->getCollection()->transform(function ($product) {
            return [
                'id' => $product->id,
                'slug' => $product->slug,
                'title' => $product->title,
                'excerpt' => $product->excerpt,
                'price_cents' => $product->price_cents,
                'price_cents_usd' => $product->price_cents_usd,
                'thumbnail_url' => $product->thumbnail_url,
                'attributes' => $product->attributes,
                'categories' => $product->categories->map(fn($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->name]),
                'tags' => $product->tags->map(fn($t) => ['id' => $t->id, 'slug' => $t->slug, 'name' => $t->name]),
            ];
        });

        return Inertia::render('Shop/Index', [
            'products' => $products,
            'categories' => ProductCategory::root()->with('children')->get(),
            'filters' => $request->only(['category', 'search', 'sort', 'direction']),
            'meta' => [
                'title' => 'Shop - Soul Bossa Nova',
                'description' => 'Browse our collection of bossa nova guitar tablatures and resources.',
            ],
        ]);
    }

    public function category(Request $request, string $slug)
    {
        $category = ProductCategory::where('slug', $slug)->firstOrFail();

        // Get category and all child category IDs
        $categoryIds = [$category->id];
        foreach ($category->children as $child) {
            $categoryIds[] = $child->id;
        }

        $query = Product::published()
            ->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('sbn_product_categories.id', $categoryIds);
            })
            ->with(['categories', 'tags']);

        // Search within category
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sort = $request->input('sort', 'title');
        $direction = $request->input('direction', 'asc');

        match ($sort) {
            'price' => $query->orderBy('price_cents', $direction),
            'date' => $query->orderBy('published_at', $direction),
            default => $query->orderBy('title', $direction),
        };

        $products = $query->paginate(20)->withQueryString();

        // Transform for Inertia
        $products->getCollection()->transform(function ($product) {
            return [
                'id' => $product->id,
                'slug' => $product->slug,
                'title' => $product->title,
                'excerpt' => $product->excerpt,
                'price_cents' => $product->price_cents,
                'price_cents_usd' => $product->price_cents_usd,
                'thumbnail_url' => $product->thumbnail_url,
                'attributes' => $product->attributes,
                'categories' => $product->categories->map(fn($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->name]),
                'tags' => $product->tags->map(fn($t) => ['id' => $t->id, 'slug' => $t->slug, 'name' => $t->name]),
            ];
        });

        return Inertia::render('Shop/Index', [
            'products' => $products,
            'categories' => ProductCategory::root()->with('children')->get(),
            'currentCategory' => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name,
            ],
            'filters' => $request->only(['search', 'sort', 'direction']),
            'meta' => [
                'title' => "{$category->name} - Shop - Soul Bossa Nova",
                'description' => "Browse {$category->name} bossa nova guitar tablatures and resources.",
            ],
        ]);
    }

    public function show(string $slug)
    {
        $product = Product::published()
            ->with(['categories', 'tags'])
            ->where('slug', $slug)
            ->firstOrFail();

        // Get related products (same category)
        $categoryIds = $product->categories->pluck('id');
        $related = Product::published()
            ->where('id', '!=', $product->id)
            ->whereHas('categories', function ($q) use ($categoryIds) {
                $q->whereIn('sbn_product_categories.id', $categoryIds);
            })
            ->limit(4)
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'slug' => $p->slug,
                    'title' => $p->title,
                    'price_cents' => $p->price_cents,
                    'price_cents_usd' => $p->price_cents_usd,
                    'thumbnail_url' => $p->thumbnail_url,
                    'attributes' => $p->attributes,
                    'categories' => $p->categories->map(fn($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->name]),
                ];
            });

        return Inertia::render('Shop/Show', [
            'product' => [
                'id' => $product->id,
                'slug' => $product->slug,
                'title' => $product->title,
                'excerpt' => $product->excerpt,
                'description' => $product->description,
                'price_cents' => $product->price_cents,
                'price_cents_usd' => $product->price_cents_usd,
                'thumbnail_url' => $product->thumbnail_url,
                'attributes' => $product->attributes,
                'categories' => $product->categories->map(fn($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->name]),
                'tags' => $product->tags->map(fn($t) => ['id' => $t->id, 'slug' => $t->slug, 'name' => $t->name]),
            ],
            'related' => $related,
            'meta' => [
                'title' => "{$product->title} - Shop - Soul Bossa Nova",
                'description' => $product->meta_description ?: substr(strip_tags($product->excerpt), 0, 160),
            ],
        ]);
    }
}
