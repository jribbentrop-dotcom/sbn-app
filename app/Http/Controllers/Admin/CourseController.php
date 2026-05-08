<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseRequest;
use App\Models\Course;
use App\Models\Product;
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
        $products = Product::orderBy('name')->get(['id', 'name', 'slug']);

        return view('admin.courses.index', compact('courses', 'products'));
    }

    public function create(): View
    {
        $course   = new Course;
        $products = Product::orderBy('name')->get(['id', 'name', 'slug']);
        $isNew    = true;

        return view('admin.courses.create', compact('course', 'products', 'isNew'));
    }

    public function store(CourseRequest $request): RedirectResponse
    {
        $data = $request->courseData();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        $course = Course::create($data);

        return redirect()->route('admin.courses.edit', $course)
            ->with('success', 'Course created.');
    }

    public function edit(Course $course): View
    {
        $course->load('lessons');
        $products = Product::orderBy('name')->get(['id', 'name', 'slug']);
        $isNew    = false;

        return view('admin.courses.edit', compact('course', 'products', 'isNew'));
    }

    public function update(CourseRequest $request, Course $course): RedirectResponse
    {
        $course->update($request->validated());

        return redirect()->route('admin.courses.edit', $course)
            ->with('success', 'Course saved.');
    }

    public function destroy(Course $course): RedirectResponse
    {
        $course->delete();

        return redirect()->route('admin.courses.index')
            ->with('success', 'Course deleted.');
    }
}
