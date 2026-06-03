@extends('layouts.admin')

@section('title', 'Messages')

@section('content')
<div class="admin-content">
    <div class="sbn-chat-shell">
        <aside class="sbn-chat-list">
            @forelse($conversations as $c)
                @php
                    $other = $c->participants->firstWhere('id', '!=', $currentUserId);
                    $title = $other?->name ?? 'Direct message';
                    $isActive = $active && $active->id === $c->id;
                @endphp
                <a href="{{ route('admin.messages.index', ['conversation' => $c->id]) }}"
                   class="sbn-chat-list-item {{ $isActive ? 'is-active' : '' }}">
                    <div class="sbn-chat-list-title">{{ $title }}</div>
                    <div class="sbn-chat-list-when">
                        {{ $c->last_message_at?->diffForHumans() ?? '—' }}
                    </div>
                </a>
            @empty
                <div class="sbn-chat-list-empty">No conversations yet.</div>
            @endforelse
        </aside>

        <section class="sbn-chat-pane">
            @if($active)
                <div class="sbn-chat-header">
                    @php $other = $active->participants->firstWhere('id', '!=', $currentUserId); @endphp
                    <strong>{{ $other?->name ?? 'Direct message' }}</strong>
                    @if($other)<span class="sbn-chat-header-subtle">{{ $other->email }}</span>@endif
                </div>

                <div class="sbn-chat-scroller">
                    @foreach($messages as $m)
                        @php $mine = $m->user_id === $currentUserId; @endphp
                        <div class="sbn-chat-bubble-row {{ $mine ? 'is-mine' : '' }}">
                            <div class="sbn-chat-bubble {{ $m->trashed() ? 'is-deleted' : '' }}">
                                @if(!$mine)<div class="sbn-chat-bubble-author">{{ $m->user?->name }}</div>@endif
                                <div class="sbn-chat-bubble-body">
                                    @if($m->trashed())
                                        <em class="sbn-chat-bubble-deleted">message removed</em>
                                    @else
                                        {{ $m->body }}
                                    @endif
                                </div>
                                <div class="sbn-chat-bubble-time">
                                    {{ $m->created_at?->format('H:i') }}
                                    @if(!$m->trashed())
                                        <form method="POST"
                                              action="{{ route('admin.messages.destroy', [$active->id, $m->id]) }}"
                                              style="display:inline" onsubmit="return confirm('Delete?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="sbn-chat-bubble-delete">delete</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <form method="POST" action="{{ route('admin.messages.store', $active->id) }}" class="sbn-chat-composer">
                    @csrf
                    <textarea name="body" class="sbn-chat-composer-input" rows="2" placeholder="Write a reply…" required></textarea>
                    <button type="submit" class="sbn-btn sbn-btn-primary sbn-btn-sm">Send</button>
                </form>
            @else
                <div class="sbn-chat-empty">Select a conversation to view it.</div>
            @endif
        </section>
    </div>
</div>
@endsection
