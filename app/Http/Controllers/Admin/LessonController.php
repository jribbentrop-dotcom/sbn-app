<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LessonFieldRequest;
use App\Http\Requests\Admin\LessonImageRequest;
use App\Http\Requests\Admin\LessonReorderRequest;
use App\Http\Requests\Admin\LessonRequest;
use App\Http\Requests\Admin\LessonStatusRequest;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\EduContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LessonController extends Controller
{
    public function create(Course $course, EduContentService $edu): View
    {
        $lesson     = new Lesson(['course_id' => $course->id, 'status' => 'publish', 'sort_order' => $course->lessons()->max('sort_order') + 1]);
        $isNew      = true;
        $widgetList = $this->widgetList($edu);

        return view('admin.lessons.edit', compact('course', 'lesson', 'isNew', 'widgetList'));
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

    public function edit(Lesson $lesson, EduContentService $edu): View
    {
        $course     = $lesson->course;
        $isNew      = false;
        $widgetList = $this->widgetList($edu);

        return view('admin.lessons.edit', compact('course', 'lesson', 'isNew', 'widgetList'));
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

    public function updateField(LessonFieldRequest $request, Lesson $lesson): JsonResponse
    {
        $validated = $request->validated();

        $lesson->update([$validated['field'] => $validated['value']]);

        return response()->json(['ok' => true]);
    }

    public function reorder(LessonReorderRequest $request, Course $course): JsonResponse
    {
        $validated = $request->validated();

        foreach ($validated['items'] as $item) {
            $course->lessons()->where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['ok' => true]);
    }

    public function uploadImage(LessonImageRequest $request, Lesson $lesson): JsonResponse
    {
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

    /** Returns [{slug, title}] for the widget palette, sorted by title. */
    public function updateStatus(LessonStatusRequest $request, Lesson $lesson): JsonResponse
    {
        $lesson->update(['status' => $request->validated('status')]);
        return response()->json(['success' => true, 'status' => $lesson->status]);
    }

    private function widgetList(EduContentService $edu): array
    {
        $list = [];
        foreach ($edu->topics('concept') as $slug => $topic) {
            $list[] = ['slug' => $slug, 'title' => $topic->title];
        }
        usort($list, fn($a, $b) => strcmp($a['title'], $b['title']));
        return $list;
    }
}
