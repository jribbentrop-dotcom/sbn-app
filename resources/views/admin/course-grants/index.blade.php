@extends('layouts.admin')

@section('content')
<div class="admin-header">
    <h1>Course Grants</h1>
    <p class="admin-subtle">Manually grant a user access to a course. Used until Stripe is wired.</p>
</div>

<div class="admin-content">
    @if(session('status'))
        <div class="admin-flash">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="admin-flash admin-flash--error">
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.course-grants.store') }}" class="admin-form admin-form--inline">
        @csrf
        <label>
            User email
            <input type="email" name="email" required>
        </label>
        <label>
            Course
            <select name="course_id" required>
                @foreach($courses as $c)
                    <option value="{{ $c->id }}">{{ $c->title }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Source
            <select name="source">
                <option value="manual_grant">Manual grant</option>
                <option value="purchase">Purchase</option>
                <option value="bundle">Bundle</option>
                <option value="promo">Promo</option>
            </select>
        </label>
        <label>
            Expires (optional)
            <input type="date" name="expires_at">
        </label>
        <button type="submit" class="btn btn-primary">Grant access</button>
    </form>

    <table class="admin-table" style="margin-top: 2rem;">
        <thead>
            <tr>
                <th>User</th>
                <th>Course</th>
                <th>Source</th>
                <th>Granted</th>
                <th>Expires</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($grants as $g)
                <tr>
                    <td>{{ $g->user_name }}<br><small>{{ $g->user_email }}</small></td>
                    <td>{{ $g->course_title }}</td>
                    <td>{{ $g->source }}</td>
                    <td>{{ $g->granted_at }}</td>
                    <td>{{ $g->expires_at ?? '—' }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.course-grants.destroy', $g->id) }}" onsubmit="return confirm('Revoke this grant?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No grants yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{ $grants->links() }}
</div>
@endsection
