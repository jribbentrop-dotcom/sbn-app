<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LessonRequest;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LessonController extends Controller
{
    public function create(Course $course): View
    {
        $lesson = new Lesson(['course_id' => $course->id, 'status' => 'publish', 'sort_order' => $course->lessons()->max('sort_order') + 1]);
        $isNew  = true;

        return view('admin.lessons.edit', compact('course', 'lesson', 'isNew'));
    }

    public function store(LessonRequest $request, Course $course): RedirectResponse
    {
        $data = $request->validated();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        $lesson = $course->lessons()->create($data);

        return redirect()->route('admin.lessons.edit', $lesson)
            ->with('success', 'Lesson created.');
    }

    public function edit(Lesson $lesson): View
    {
        $course = $lesson->course;
        $isNew  = false;

        return view('admin.lessons.edit', compact('course', 'lesson', 'isNew'));
    }

    public function update(LessonRequest $request, Lesson $lesson): RedirectResponse
    {
        $lesson->update($request->validated());

        return redirect()->route('admin.lessons.edit', $lesson)
            ->with('success', 'Lesson saved.');
    }

    public function destroy(Lesson $lesson): RedirectResponse
    {
        $course = $lesson->course;
        $lesson->delete();

        return redirect()->route('admin.courses.edit', $course)
            ->with('success', 'Lesson deleted.');
    }

    public function updateField(Request $request, Lesson $lesson): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', \Illuminate\Validation\Rule::in(['section_title', 'title', 'status'])],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        $lesson->update([$validated['field'] => $validated['value']]);

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'items'             => ['required', 'array'],
            'items.*.id'        => ['required', 'integer'],
            'items.*.sort_order' => ['required', 'integer'],
        ]);

        foreach ($validated['items'] as $item) {
            $course->lessons()->where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['ok' => true]);
    }

    public function uploadImage(Request $request, Lesson $lesson): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        $file = $request->file('image');
        $uuid = (string) Str::uuid();
        $ext  = $file->getClientOriginalExtension();
        $path = "images/lessons/{$lesson->id}/{$uuid}.{$ext}";

        $file->move(public_path("images/lessons/{$lesson->id}"), "{$uuid}.{$ext}");

        return response()->json(['url' => asset($path)]);
    }

    public function getImages(Lesson $lesson): JsonResponse
    {
        $dir = public_path("images/lessons/{$lesson->id}");
        if (!\Illuminate\Support\Facades\File::exists($dir)) {
            return response()->json(['images' => []]);
        }

        $files = \Illuminate\Support\Facades\File::files($dir);
        $images = array_map(function ($f) use ($lesson) {
            $filename = $f->getFilename();
            return [
                'url' => asset("images/lessons/{$lesson->id}/{$filename}"),
                'name' => $filename,
            ];
        }, $files);

        return response()->json(['images' => $images]);
    }
}
