@extends('layouts.admin')

@section('title', $channel->title)

@section('content')
<div class="admin-content">
    <div class="sbn-community-bar">
        <form method="POST" action="{{ route('admin.community.read-only') }}">
            @csrf
            <button type="submit" class="sbn-btn sbn-btn-secondary sbn-btn-sm">
                {{ $channel->read_only ? 'Open to everyone' : 'Set announcements-only' }}
            </button>
        </form>
        @if($channel->read_only)
            <span class="sbn-community-banner">Announcements-only — only you can post.</span>
        @endif
    </div>

    <div class="sbn-chat-shell sbn-chat-shell--single">
        <section class="sbn-chat-pane">
            <div class="sbn-chat-scroller">
                @forelse($messages as $m)
                    @php $mine = $m->user_id === $currentUserId; @endphp
                    <div class="sbn-chat-bubble-row {{ $mine ? 'is-mine' : '' }}">
                        <div class="sbn-chat-bubble {{ $m->trashed() ? 'is-deleted' : '' }}">
                            <div class="sbn-chat-bubble-author">{{ $m->user?->name }}</div>
                            <div class="sbn-chat-bubble-body">
                                @if($m->trashed())
                                    <em class="sbn-chat-bubble-deleted">message removed</em>
                                @else
                                    {{ $m->body }}
                                @endif
                            </div>
                            <div class="sbn-chat-bubble-time">
                                {{ $m->created_at?->format('Y-m-d H:i') }}
                                @if(!$m->trashed())
                                    <form method="POST"
                                          action="{{ route('admin.community.message.destroy', $m->id) }}"
                                          style="display:inline" onsubmit="return confirm('Delete?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="sbn-chat-bubble-delete">delete</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="sbn-chat-empty">No messages yet.</div>
                @endforelse
            </div>

            <form method="POST" action="{{ route('admin.community.store') }}" class="sbn-chat-composer">
                @csrf
                <textarea name="body" class="sbn-chat-composer-input" rows="2" placeholder="Post to community…" required></textarea>
                <button type="submit" class="sbn-btn sbn-btn-primary sbn-btn-sm">Post</button>
            </form>
        </section>
    </div>
</div>
@endsection
