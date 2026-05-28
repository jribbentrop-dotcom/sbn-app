@extends('layouts.admin')

@section('title', $channel->title)

@section('content')
<div class="admin-content">
    <div class="sbn-admin-community-bar">
        <form method="POST" action="{{ route('admin.community.read-only') }}">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">
                {{ $channel->read_only ? 'Open to everyone' : 'Set announcements-only' }}
            </button>
        </form>
        @if($channel->read_only)
            <span class="badge badge-warning">Announcements-only — only you can post.</span>
        @endif
    </div>

    <div class="sbn-admin-chat sbn-admin-chat--single">
        <section class="sbn-admin-chat-pane">
            <div class="sbn-admin-chat-scroller">
                @forelse($messages as $m)
                    @php $mine = $m->user_id === $currentUserId; @endphp
                    <div class="sbn-admin-chat-bubble-row {{ $mine ? 'is-mine' : '' }}">
                        <div class="sbn-admin-chat-bubble {{ $m->trashed() ? 'is-deleted' : '' }}">
                            <div class="sbn-admin-chat-author">{{ $m->user?->name }}</div>
                            <div class="sbn-admin-chat-body">
                                @if($m->trashed())
                                    <em>message removed</em>
                                @else
                                    {{ $m->body }}
                                @endif
                            </div>
                            <div class="sbn-admin-chat-time">
                                {{ $m->created_at?->format('Y-m-d H:i') }}
                                @if(!$m->trashed())
                                    <form method="POST"
                                          action="{{ route('admin.community.message.destroy', $m->id) }}"
                                          style="display:inline" onsubmit="return confirm('Delete?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="sbn-admin-chat-delete">delete</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="sbn-admin-chat-empty">No messages yet.</div>
                @endforelse
            </div>

            <form method="POST" action="{{ route('admin.community.store') }}" class="sbn-admin-chat-composer">
                @csrf
                <textarea name="body" rows="2" placeholder="Post to community…" required></textarea>
                <button type="submit" class="btn btn-primary">Post</button>
            </form>
        </section>
    </div>
</div>
@endsection
