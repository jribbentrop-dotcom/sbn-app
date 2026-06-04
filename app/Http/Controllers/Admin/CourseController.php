<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseRequest;
use App\Models\Course;
use App\Models\Product;
use App\Models\SbnTag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CourseController extends Controller
{
    public function index(Request $request): View
    {
        $query = Course::withCount(['lessons' => fn ($q) => $q->where('status', 'publish')]);

        if ($search = $request->get('search')) {
            $query->where('title', 'like', "%{$search}%");
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $courses  = $query->orderBy('sort_order')->orderBy('title')->paginate(25)->withQueryString();
        $products = Product::orderBy('title')->get(['id', 'title', 'slug']);

        return view('admin.courses.index', compact('courses', 'products'));
    }

    public function create(): View
    {
        $course       = new Course;
        $products     = Product::orderBy('title')->get(['id', 'title', 'slug']);
        $isNew        = true;
        $existingTags = '';

        return view('admin.courses.create', compact('course', 'products', 'isNew', 'existingTags'));
    }

    public function store(CourseRequest $request): RedirectResponse
    {
        $data = $request->courseData();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        $course = Course::create($data);
        $this->syncTags($course, $request->tagSlugs());

        return redirect()->route('admin.courses.edit', $course)
            ->with('success', 'Course created.');
    }

    public function edit(Course $course): View
    {
        $course->load('lessons');
        $products     = Product::orderBy('title')->get(['id', 'title', 'slug']);
        $isNew        = false;
        $existingTags = $course->tags()->pluck('slug')->implode(',');

        return view('admin.courses.edit', compact('course', 'products', 'isNew', 'existingTags'));
    }

    public function update(CourseRequest $request, Course $course): RedirectResponse
    {
        $course->update($request->courseData());
        $this->syncTags($course, $request->tagSlugs());

        return redirect()->route('admin.courses.edit', $course)
            ->with('success', 'Course saved.');
    }

    public function destroy(Course $course): RedirectResponse
    {
        $course->delete();

        return redirect()->route('admin.courses.index')
            ->with('success', 'Course deleted.');
    }

    private function syncTags(Course $course, array $slugs): void
    {
        $ids = collect($slugs)->map(
            fn ($slug) => SbnTag::findOrCreateBySlug($slug)->id
        )->all();

        $course->tags()->sync($ids);
    }

    public function updateStatus(Request $request, Course $course): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate(['status' => 'required|in:draft,publish']);
        $course->update(['status' => $validated['status']]);
        return response()->json(['success' => true, 'status' => $course->status]);
    }

    public function updateDescription(Request $request, Course $course): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate(['description' => 'nullable|string|max:10000']);
        $course->update(['description' => $validated['description'] ?? '']);
        return response()->json(['success' => true, 'description' => $course->description]);
    }
}
