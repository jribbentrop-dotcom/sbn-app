{{-- Shared course meta fields. Used by create.blade.php and edit.blade.php --}}

<div class="sbn-editor-card">
    <div class="sbn-editor-card-header">
        <h2>{{ $isNew ? 'New Course' : 'Course Details' }}</h2>
    </div>
    <div class="sbn-editor-card-body">

        <div class="sbn-form-row sbn-form-row-2">
            <div class="sbn-form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('title', $course->title) }}"
                       x-model="form.title"
                       @input="autoSlug()"
                       placeholder="e.g. Bossa Nova Basics" required>
                @error('title')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
            </div>
            <div class="sbn-form-group">
                <label for="slug">Slug</label>
                <input type="text" id="slug" name="slug" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('slug', $course->slug) }}"
                       x-model="form.slug"
                       @input="manualSlug = true"
                       placeholder="e.g. bossa-nova-basics"
                       pattern="[a-z0-9\-]+" required>
                <p class="sbn-form-hint">Lowercase, numbers, hyphens only.</p>
                @error('slug')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="sbn-form-group">
            <label for="excerpt">Excerpt</label>
            <textarea id="excerpt" name="excerpt" class="sbn-search-input" style="padding:10px 14px; height:80px; resize:vertical;"
                      placeholder="Short description shown on course cards">{{ old('excerpt', $course->excerpt) }}</textarea>
        </div>

        <div class="sbn-form-row sbn-form-row-3">
            <div class="sbn-form-group">
                <label for="genres">Genres <span class="sbn-form-hint">(comma-separated)</span></label>
                <input type="text" id="genres" name="genres_raw" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('genres_raw', implode(', ', $course->genres ?? [])) }}"
                       placeholder="e.g. bossa-nova, jazz">
                @error('genres')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
            </div>
            <div class="sbn-form-group">
                <label for="levels">Levels <span class="sbn-form-hint">(comma-separated)</span></label>
                <input type="text" id="levels" name="levels_raw" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('levels_raw', implode(', ', $course->levels ?? [])) }}"
                       placeholder="e.g. basic, intermediate">
            </div>
            <div class="sbn-form-group">
                <label for="topics">Topics <span class="sbn-form-hint">(comma-separated)</span></label>
                <input type="text" id="topics" name="topics_raw" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('topics_raw', implode(', ', $course->topics ?? [])) }}"
                       placeholder="e.g. rhythm, harmony">
            </div>
        </div>

        <div class="sbn-form-row sbn-form-row-3">
            <div class="sbn-form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="sbn-search-input" style="padding-left:14px;">
                    <option value="publish" @selected(old('status', $course->status ?? 'publish') === 'publish')>Published</option>
                    <option value="draft"   @selected(old('status', $course->status) === 'draft')>Draft</option>
                </select>
            </div>
            <div class="sbn-form-group">
                <label for="product_id">Linked product</label>
                <select id="product_id" name="product_id" class="sbn-search-input" style="padding-left:14px;">
                    <option value="">— Free / no product —</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" @selected(old('product_id', $course->product_id) == $product->id)>
                            {{ $product->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="sbn-form-group">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="hidden" name="is_free" value="0">
                    <input type="checkbox" name="is_free" value="1"
                           @checked(old('is_free', $course->is_free))>
                    Free course (no purchase required)
                </label>
            </div>
        </div>

        <div class="sbn-form-row sbn-form-row-2">
            <div class="sbn-form-group">
                <label for="featured_image_path">Featured image path</label>
                <input type="text" id="featured_image_path" name="featured_image_path" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('featured_image_path', $course->featured_image_path) }}"
                       placeholder="e.g. images/courses/bossa-basics.webp">
            </div>
            <div class="sbn-form-group">
                <label for="sort_order">Sort order</label>
                <input type="number" id="sort_order" name="sort_order" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('sort_order', $course->sort_order ?? 0) }}" min="0">
            </div>
        </div>

    </div>
</div>
