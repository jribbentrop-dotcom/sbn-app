@extends('layouts.admin')

@section('title', $isNew ? 'New Skill Node' : 'Edit Skill Node')

@section('actions')
    <a href="{{ route('admin.skill-nodes.index') }}" class="sbn-btn sbn-btn-secondary">← Back</a>
@endsection

@section('content')

<form method="POST"
      action="{{ $isNew ? route('admin.skill-nodes.store') : route('admin.skill-nodes.update', $node) }}">
    @csrf
    @unless($isNew) @method('PUT') @endunless

    <div class="sbn-editor-card">
        <div class="sbn-editor-card-header">
            <h2>{{ $isNew ? 'New Skill Node' : 'Node Details' }}</h2>
        </div>
        <div class="sbn-editor-card-body">

            <div class="sbn-form-row sbn-form-row-2">
                <div class="sbn-form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" class="sbn-search-input" style="padding-left:14px;"
                           value="{{ old('title', $node->title) }}"
                           placeholder="e.g. Drop 2 Voicings" required>
                    @error('title')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
                </div>
                <div class="sbn-form-group">
                    <label for="slug">Slug <span style="font-weight:400;color:var(--clr-text-muted)">(blank = from title)</span></label>
                    <input type="text" id="slug" name="slug" class="sbn-search-input" style="padding-left:14px;"
                           value="{{ old('slug', $node->slug) }}"
                           placeholder="e.g. drop2-voicings" pattern="[a-z0-9\-]*">
                    @error('slug')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="sbn-form-row sbn-form-row-2">
                <div class="sbn-form-group">
                    <label for="branch">Branch</label>
                    <select id="branch" name="branch" class="sbn-search-input" style="padding-left:14px;" required>
                        @foreach($branches as $b)
                            <option value="{{ $b }}" @selected(old('branch', $node->branch) === $b)>
                                {{ ucwords(str_replace('-', ' ', $b)) }}
                            </option>
                        @endforeach
                    </select>
                    @error('branch')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
                </div>
                <div class="sbn-form-group">
                    <label for="sub_branch">Sub-branch <span style="font-weight:400;color:var(--clr-text-muted)">(optional)</span></label>
                    <input type="text" id="sub_branch" name="sub_branch" class="sbn-search-input" style="padding-left:14px;"
                           value="{{ old('sub_branch', $node->sub_branch) }}"
                           placeholder="e.g. Voicings">
                </div>
            </div>

            <div class="sbn-form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="sbn-search-input" style="padding:10px 14px; height:90px; resize:vertical;"
                          placeholder="What this node teaches…">{{ old('description', $node->description) }}</textarea>
            </div>

            <div class="sbn-form-row sbn-form-row-2">
                <div class="sbn-form-group">
                    <label for="content_tag_slug">Content tag <span style="font-weight:400;color:var(--clr-text-muted)">(optional)</span></label>
                    <input type="text" id="content_tag_slug" name="content_tag_slug" class="sbn-search-input" style="padding-left:14px;"
                           value="{{ old('content_tag_slug', $node->content_tag_slug) }}"
                           placeholder="e.g. samba">
                    <p class="sbn-form-hint">Borrows an existing tag (sbn_tags.slug) to auto-discover related content. Leave blank if none aligns.</p>
                </div>
                <div class="sbn-form-group">
                    <label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" class="sbn-search-input" style="padding-left:14px;"
                           value="{{ old('sort_order', $node->sort_order ?? 0) }}" min="0">
                </div>
            </div>

        </div>
    </div>

    <div class="sbn-editor-card" style="margin-top:18px;">
        <div class="sbn-editor-card-header">
            <h2>Relationships</h2>
        </div>
        <div class="sbn-editor-card-body">

            <div class="sbn-form-row sbn-form-row-2">
                <div class="sbn-form-group">
                    <label for="prereqs">Prerequisites <span style="font-weight:400;color:var(--clr-text-muted)">(nodes required before this one)</span></label>
                    <select id="prereqs" name="prereqs[]" multiple class="sbn-search-input" style="padding:8px 10px; height:200px;">
                        @foreach($allNodes as $other)
                            <option value="{{ $other->id }}" @selected(in_array($other->id, old('prereqs', $selectedPrereqs)))>
                                {{ $other->title }} ({{ $other->branch }})
                            </option>
                        @endforeach
                    </select>
                    <p class="sbn-form-hint">Ctrl/Cmd-click to select multiple.</p>
                    @error('prereqs')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
                </div>
                <div class="sbn-form-group">
                    <label for="courses">Taught by courses</label>
                    <select id="courses" name="courses[]" multiple class="sbn-search-input" style="padding:8px 10px; height:200px;">
                        @foreach($allCourses as $course)
                            <option value="{{ $course->id }}" @selected(in_array($course->id, old('courses', $selectedCourses)))>
                                {{ $course->title }}
                            </option>
                        @endforeach
                    </select>
                    <p class="sbn-form-hint">A node can be taught by many courses (and vice versa).</p>
                </div>
            </div>

        </div>
    </div>

    <div style="margin-top:20px;display:flex;gap:12px;align-items:center;">
        <button type="submit" class="sbn-btn sbn-btn-primary sbn-btn-lg">
            {{ $isNew ? 'Create Node' : 'Save Changes' }}
        </button>
        <a href="{{ route('admin.skill-nodes.index') }}" class="sbn-btn sbn-btn-secondary">Cancel</a>
    </div>
</form>

@endsection
