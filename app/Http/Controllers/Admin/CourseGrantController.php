<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use App\Services\CourseAccessService;
use Illuminate\Http\Request;

class CourseGrantController extends Controller
{
    public function index(Request $request)
    {
        $grants = \DB::table('course_user')
            ->join('users', 'users.id', '=', 'course_user.user_id')
            ->join('sbn_courses', 'sbn_courses.id', '=', 'course_user.course_id')
            ->select(
                'course_user.id',
                'users.email as user_email',
                'users.name as user_name',
                'sbn_courses.title as course_title',
                'sbn_courses.slug as course_slug',
                'course_user.source',
                'course_user.granted_at',
                'course_user.expires_at'
            )
            ->orderByDesc('course_user.granted_at')
            ->paginate(50);

        $courses = Course::orderBy('title')->get(['id', 'slug', 'title']);

        return view('admin.course-grants.index', [
            'grants'  => $grants,
            'courses' => $courses,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email'      => ['required', 'email'],
            'course_id'  => ['required', 'exists:sbn_courses,id'],
            'source'     => ['required', 'in:purchase,manual_grant,bundle,promo'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            return back()->withErrors(['email' => 'No user with that email.']);
        }

        $course = Course::findOrFail($data['course_id']);
        $expiresAt = !empty($data['expires_at']) ? \Carbon\Carbon::parse($data['expires_at']) : null;

        app(CourseAccessService::class)->grantManual($user, $course, $expiresAt);

        return back()->with('status', "Granted {$user->email} access.");
    }

    public function destroy(Request $request, int $id)
    {
        app(CourseAccessService::class)->revokeById($id);
        return back()->with('status', 'Grant revoked.');
    }
}
