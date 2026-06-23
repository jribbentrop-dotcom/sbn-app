@extends('layouts.admin')

@section('title', 'Skill Nodes')

@section('actions')
    <a href="{{ route('admin.skill-nodes.create') }}" class="sbn-btn sbn-btn-primary">+ New Skill Node</a>
@endsection

@section('content')

@if(session('success'))
    <div class="sbn-flash sbn-flash-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif

@if($nodes->isEmpty())
    <div class="sbn-empty-state">
        <div class="sbn-empty-icon">🧭</div>
        <h3>No skill nodes yet</h3>
        <p>Skill nodes are atomic teachable concepts that form the learning graph. Seed a starter set with <code>SkillNodeSeeder</code> or create one by hand.</p>
        <a href="{{ route('admin.skill-nodes.create') }}" class="sbn-btn sbn-btn-primary">Create your first node</a>
    </div>
@else
    @foreach($nodes as $branch => $branchNodes)
    <h2 style="margin:24px 0 8px;text-transform:capitalize;">{{ str_replace('-', ' ', $branch) }}</h2>
    <div class="sbn-list-table-wrap">
        <table class="sbn-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Slug</th>
                    <th>Sub-branch</th>
                    <th title="Prerequisites">Req</th>
                    <th title="Nodes this unlocks">Unlocks</th>
                    <th title="Courses teaching this node">Courses</th>
                    <th>Content tag</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($branchNodes as $node)
                <tr>
                    <td><strong>{{ $node->title }}</strong></td>
                    <td><code style="font-size:12px;">{{ $node->slug }}</code></td>
                    <td style="font-size:13px;color:var(--clr-text-dim);">{{ $node->sub_branch ?: '—' }}</td>
                    <td style="font-size:13px;color:var(--clr-text-dim);">{{ $node->prerequisites_count }}</td>
                    <td style="font-size:13px;color:var(--clr-text-dim);">{{ $node->unlocks_count }}</td>
                    <td style="font-size:13px;color:var(--clr-text-dim);">{{ $node->courses_count }}</td>
                    <td>
                        @if($node->content_tag_slug)
                            <span class="sbn-badge sbn-badge-green">#{{ $node->content_tag_slug }}</span>
                        @else
                            <span style="color:var(--clr-text-dim);">—</span>
                        @endif
                    </td>
                    <td>
                        <div class="sbn-list-actions">
                            <a href="{{ route('admin.skill-nodes.edit', $node) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a>
                            <form method="POST" action="{{ route('admin.skill-nodes.destroy', $node) }}"
                                  onsubmit="return confirm('Delete {{ addslashes($node->title) }}? This removes its prerequisite, course, and progress links.')">
                                @csrf @method('DELETE')
                                <button type="submit" class="sbn-btn-sm sbn-btn-sm-danger">Del</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endforeach
@endif

@endsection
