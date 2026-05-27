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
                <label for="category">Category</label>
                <select id="category" name="category" class="sbn-search-input" style="padding-left:14px;">
                    <option value="">— None —</option>
                    @foreach(\App\Models\ChordProgression::CATEGORIES as $cat)
                        <option value="{{ $cat }}" @selected(old('category', $course->category) === $cat)>
                            {{ \App\Models\ChordProgression::CATEGORY_LABELS[$cat] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="sbn-form-group">
                <label for="levels">Difficulty</label>
                @php
                    $selectedLevel = old('levels_raw', $course->levels[0] ?? '');
                @endphp
                <select id="levels" name="levels_raw" class="sbn-search-input" style="padding-left:14px;">
                    <option value="">— None —</option>
                    <option value="basic"              @selected($selectedLevel === 'basic')>Basic</option>
                    <option value="early-intermediate" @selected($selectedLevel === 'early-intermediate')>Early Intermediate</option>
                    <option value="intermediate"       @selected($selectedLevel === 'intermediate')>Intermediate</option>
                    <option value="late-intermediate"  @selected($selectedLevel === 'late-intermediate')>Late Intermediate</option>
                    <option value="advanced"           @selected($selectedLevel === 'advanced')>Advanced</option>
                </select>
            </div>
            <div class="sbn-form-group">
                <label for="topics">Topics <span class="sbn-form-hint">(comma-separated)</span></label>
                <input type="text" id="topics" name="topics_raw" class="sbn-search-input" style="padding-left:14px;"
                       value="{{ old('topics_raw', implode(', ', $course->topics ?? [])) }}"
                       placeholder="e.g. rhythm, harmony">
            </div>
        </div>

        {{-- Hashtags --}}
        <div class="sbn-form-group">
            <label>Hashtags</label>
            <input type="hidden" name="tags" x-bind:value="courseTags.join(',')">

            <div class="sbn-tags-active">
                <template x-if="courseTags.length === 0">
                    <span class="sbn-tags-none">No hashtags yet — click below to add</span>
                </template>
                <template x-for="tag in courseTags" :key="tag">
                    <span class="sbn-tag-chip">
                        <span x-text="'#' + tag"></span>
                        <button type="button" class="sbn-tag-remove" @click="courseTags = courseTags.filter(t => t !== tag)">×</button>
                    </span>
                </template>
            </div>

            <p class="sbn-field-hint" style="margin: 8px 0 4px;">Click to add:</p>
            <div class="sbn-tags-palette">
                @foreach(\App\Models\Course::PRESET_TAGS as $preset)
                    <button type="button"
                            class="sbn-tag-preset"
                            :class="courseTags.includes('{{ $preset }}') && 'is-active'"
                            @click="courseTags.includes('{{ $preset }}') ? courseTags = courseTags.filter(t => t !== '{{ $preset }}') : courseTags.push('{{ $preset }}')">
                        #{{ $preset }}
                    </button>
                @endforeach
            </div>

            <div class="sbn-tags-custom">
                <input type="text" class="sbn-input" placeholder="Custom hashtag…" style="max-width:200px;"
                       x-ref="courseCustomTag"
                       @keydown.enter.prevent="
                           const v = $refs.courseCustomTag.value.trim().toLowerCase();
                           if (v && !courseTags.includes(v)) courseTags.push(v);
                           $refs.courseCustomTag.value = '';
                       ">
                <button type="button" class="sbn-btn sbn-btn-secondary" style="padding:7px 14px;"
                        @click="
                            const v = $refs.courseCustomTag.value.trim().toLowerCase();
                            if (v && !courseTags.includes(v)) courseTags.push(v);
                            $refs.courseCustomTag.value = '';
                        ">Add</button>
            </div>
            <p class="sbn-field-hint">Hashtags are cross-site — clicking one shows all content tagged with it.</p>
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
