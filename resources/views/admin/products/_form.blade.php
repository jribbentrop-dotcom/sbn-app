{{-- Shared product meta fields. Used by create.blade.php and edit.blade.php --}}

<div class="sbn-editor-card">
    <div class="sbn-editor-card-header">
        <h2>{{ $isNew ? 'New Product' : 'Product Details' }}</h2>
    </div>
    <div class="sbn-editor-card-body">

        <div class="sbn-form-row sbn-form-row-2">
            <div class="sbn-form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('title', $product->title) }}"
                       x-model="form.title"
                       @input="autoSlug()"
                       placeholder="e.g. Bossa Nova Chord Pack" required>
                @error('title')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
            </div>
            <div class="sbn-form-group">
                <label for="slug">Slug</label>
                <input type="text" id="slug" name="slug" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('slug', $product->slug) }}"
                       x-model="form.slug"
                       @input="manualSlug = true"
                       placeholder="e.g. bossa-nova-chord-pack"
                       pattern="[a-z0-9\-]*">
                <p class="sbn-form-hint">Lowercase, numbers, hyphens only. Auto-generated from title if blank.</p>
                @error('slug')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="sbn-form-row sbn-form-row-2">
            <div class="sbn-form-group">
                <label for="price">Price (EUR)</label>
                <input type="number" id="price" name="price" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('price', $product->price_cents !== null ? $product->price_cents / 100 : '') }}"
                       step="0.01" min="0" placeholder="e.g. 29.99" required>
                <p class="sbn-form-hint">Enter amount in euros (e.g. 29.99). Stored as cents internally.</p>
                @error('price')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
            </div>
            <div class="sbn-form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="sbn-search-input" style="padding-left:14px;">
                    <option value="published" @selected(old('status', $product->status ?? 'draft') === 'published')>Published</option>
                    <option value="draft"     @selected(old('status', $product->status ?? 'draft') === 'draft')>Draft</option>
                </select>
                @error('status')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="sbn-form-group">
            <label for="excerpt">Excerpt</label>
            <textarea id="excerpt" name="excerpt" class="sbn-search-input" style="padding:10px 14px; height:80px; resize:vertical;"
                      placeholder="Short description shown on product cards">{{ old('excerpt', $product->excerpt) }}</textarea>
        </div>

        <div class="sbn-form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" class="sbn-search-input" style="padding:10px 14px; height:140px; resize:vertical;"
                      placeholder="Full product description">{{ old('description', $product->description) }}</textarea>
        </div>

        <div class="sbn-form-group">
            <label for="meta_description">Meta description</label>
            <textarea id="meta_description" name="meta_description" class="sbn-search-input" style="padding:10px 14px; height:70px; resize:vertical;"
                      placeholder="SEO meta description (150–160 chars)">{{ old('meta_description', $product->meta_description) }}</textarea>
        </div>

        <div class="sbn-form-group">
            <label for="thumbnail_path">Thumbnail path</label>
            <input type="text" id="thumbnail_path" name="thumbnail_path" class="sbn-search-input" style="padding-left:14px;"
                   value="{{ old('thumbnail_path', $product->thumbnail_path) }}"
                   placeholder="e.g. images/products/bossa-nova-chord-pack.webp">
            <p class="sbn-form-hint">Relative path under <code>public/</code>.</p>
        </div>

        @if(isset($categories) && $categories->isNotEmpty())
        <div class="sbn-form-group">
            <label for="categories">Categories</label>
            <select id="categories" name="categories[]" class="sbn-search-input" style="padding-left:14px;" multiple size="5">
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}"
                        @selected(in_array($cat->id, old('categories', $selectedCategories ?? [])))>
                        {{ $cat->name }}
                    </option>
                @endforeach
            </select>
            <p class="sbn-form-hint">Hold Ctrl / Cmd to select multiple.</p>
        </div>
        @endif

    </div>
</div>

{{-- Payments (Stripe) --}}
<div class="sbn-editor-card" style="margin-top:16px;">
    <div class="sbn-editor-card-header">
        <h2>Payments (Stripe)</h2>
    </div>
    <div class="sbn-editor-card-body">

        <div class="sbn-form-row sbn-form-row-2">
            <div class="sbn-form-group">
                <label for="payment_ref">Stripe Price ID</label>
                <input type="text" id="payment_ref" name="payment_ref" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('payment_ref', $product->payment_ref) }}"
                       placeholder="e.g. price_1AbcXXXXXXXXXXXX">
                <p class="sbn-form-hint">The Stripe Price ID (<code>price_…</code>) used for checkout. Leave blank until Stripe is configured.</p>
                @error('payment_ref')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
            </div>
            <div class="sbn-form-group">
                <label for="tax_code">Stripe tax code</label>
                <input type="text" id="tax_code" name="tax_code" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('tax_code', $product->tax_code ?? '') }}"
                       placeholder="e.g. txcd_10000001">
                <p class="sbn-form-hint">Stripe product tax code used for automatic tax calculation.</p>
                @error('tax_code')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
            </div>
        </div>

    </div>
</div>
