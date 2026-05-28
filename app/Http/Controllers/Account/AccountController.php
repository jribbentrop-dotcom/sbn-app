<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Order;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AccountController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();

        $recentCourses = $user->courses()
            ->orderByDesc('course_user.last_accessed_at')
            ->orderByDesc('course_user.granted_at')
            ->limit(4)
            ->get()
            ->map(fn (Course $c) => $this->courseRow($c));

        $orderCount = Order::where('guest_email', $user->email)->count();

        return Inertia::render('Account/Dashboard', [
            'recentCourses' => $recentCourses,
            'orderCount'    => $orderCount,
        ]);
    }

    public function courses(Request $request)
    {
        $user = $request->user();

        $courses = $user->courses()
            ->with('lessons')
            ->orderByDesc('course_user.last_accessed_at')
            ->orderByDesc('course_user.granted_at')
            ->get()
            ->map(fn (Course $c) => $this->courseRow($c));

        return Inertia::render('Account/Courses', [
            'courses' => $courses,
        ]);
    }

    public function orders(Request $request)
    {
        $user = $request->user();

        $orders = Order::where('guest_email', $user->email)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Order $o) => [
                'id'              => $o->id,
                'token'           => $o->token,
                'status'          => $o->status,
                'total_formatted' => $o->total_formatted,
                'created_at'      => $o->created_at?->toIso8601String(),
                'item_count'      => $o->items()->count(),
            ]);

        return Inertia::render('Account/Orders/Index', [
            'orders' => $orders,
        ]);
    }

    public function order(Request $request, string $token)
    {
        $user = $request->user();

        $order = Order::where('token', $token)
            ->where('guest_email', $user->email)
            ->with(['items', 'downloadGrants'])
            ->firstOrFail();

        return Inertia::render('Account/Orders/Show', [
            'order' => [
                'id'              => $order->id,
                'token'           => $order->token,
                'status'          => $order->status,
                'total_formatted' => $order->total_formatted,
                'created_at'      => $order->created_at?->toIso8601String(),
                'items'           => $order->items->map(fn ($i) => [
                    'title'    => $i->title ?? $i->product_title ?? '(item)',
                    'quantity' => $i->quantity ?? 1,
                ]),
                'downloads' => $order->downloadGrants->map(fn ($g) => [
                    'token'      => $g->token,
                    'product_id' => $g->product_id,
                    'expires_at' => $g->expires_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        $profile = UserProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['display_name' => $user->name]
        );

        return Inertia::render('Account/Profile', [
            'profile' => [
                'display_name' => $profile->display_name,
                'bio'          => $profile->bio,
                'avatar_url'   => $profile->avatar_path ? Storage::url($profile->avatar_path) : null,
                'public'       => $profile->public,
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:80'],
            'bio'          => ['nullable', 'string', 'max:2000'],
            'public'       => ['boolean'],
        ]);

        $user = $request->user();
        $profile = UserProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->fill($data)->save();

        return back();
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $user = $request->user();
        $profile = UserProfile::firstOrCreate(['user_id' => $user->id]);

        if ($profile->avatar_path) {
            Storage::disk('public')->delete($profile->avatar_path);
        }

        $ext = $request->file('avatar')->getClientOriginalExtension();
        $path = $request->file('avatar')->storeAs(
            'avatars',
            $user->id . '-' . Str::random(8) . '.' . $ext,
            'public'
        );

        $profile->avatar_path = $path;
        $profile->save();

        return back();
    }

    private function courseRow(Course $course): array
    {
        return [
            'id'                => $course->id,
            'slug'              => $course->slug,
            'title'             => $course->title,
            'primaryGenre'      => $course->primary_genre,
            'primaryLevel'      => $course->primary_level,
            'lessonCount'       => $course->lesson_count,
            'featuredImagePath' => $course->featured_image_path,
            'source'            => $course->pivot->source ?? null,
            'grantedAt'         => $course->pivot->granted_at ?? null,
            'lastAccessedAt'    => $course->pivot->last_accessed_at ?? null,
        ];
    }
}
