@extends('layouts.admin')

@section('title', 'Skill Node Coverage')

@section('actions')
    <a href="{{ route('admin.skill-nodes.index') }}" class="sbn-btn sbn-btn-secondary">← Nodes</a>
    <a href="{{ route('admin.skill-nodes.layout') }}" class="sbn-btn sbn-btn-secondary">🗺 Tree layout</a>
    <a href="{{ route('admin.skill-nodes.create') }}" class="sbn-btn sbn-btn-primary">+ New Skill Node</a>
@endsection

@push('styles')
<style>
    .sbn-coverage-section { margin-bottom: 12px; scroll-margin-top: 80px; }
    .sbn-coverage-section:target > details { outline: 2px solid var(--clr-accent-border, #c7d2fe); border-radius: 8px; }

    .sbn-coverage-details {
        border: 1px solid var(--clr-border);
        border-radius: var(--radius);
        background: var(--clr-white);
        overflow: hidden;
    }
    .sbn-coverage-details summary {
        display: flex; align-items: center; gap: 10px;
        padding: 13px 18px;
        cursor: pointer;
        user-select: none;
        list-style: none; /* remove default marker */
        font-size: 14px; font-weight: 600; color: var(--clr-text);
    }
    .sbn-coverage-details summary::-webkit-details-marker { display: none; }
    .sbn-coverage-details summary::before {
        content: '▶';
        font-size: 10px; color: var(--clr-text-muted);
        transition: transform 0.15s;
        flex-shrink: 0;
    }
    .sbn-coverage-details[open] summary::before { transform: rotate(90deg); }
    .sbn-coverage-details summary:hover { background: var(--clr-surface-2, #f7f7f5); }

    .sbn-coverage-count {
        margin-left: auto;
        font-size: 12px; font-weight: 600;
        padding: 2px 8px; border-radius: 20px;
        flex-shrink: 0;
    }
    .sbn-coverage-count--warn { background: var(--clr-warning-bg, #fef3c7); color: var(--clr-warning-text, #92400e); }
    .sbn-coverage-count--ok   { background: var(--clr-success-bg, #d1fae5); color: var(--clr-success-text, #065f46); }

    .sbn-coverage-body { padding: 0 18px 16px; }
    .sbn-coverage-note { font-size: 13px; color: var(--clr-text-dim); margin: 10px 0 12px; }
    .sbn-coverage-empty { font-size: 13px; color: var(--clr-success, #16a34a); padding: 6px 0 2px; }
    .sbn-coverage-cats { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
    .sbn-coverage-cat-badge { background: var(--clr-surface-2); border: 1px solid var(--clr-border); border-radius: 6px; padding: 4px 10px; font-size: 13px; font-family: var(--font-mono, monospace); }

    .sbn-coverage-group-label {
        font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em;
        color: var(--clr-text-muted); padding: 20px 0 6px;
    }
</style>
@endpush

@section('content')

<p style="color:var(--clr-text-dim);font-size:13px;margin-bottom:20px;">
    Gap lists for the skill node ↔ content interconnect. Counts are live.
    Numbers on the <a href="{{ route('admin.dashboard') }}">dashboard</a> link here.
</p>

{{-- ── NODE-SIDE GAPS ────────────────────────────────────────── --}}

<p class="sbn-coverage-group-label">Node-side gaps</p>

<div id="nodes-no-content" class="sbn-coverage-section">
    @php $rows = $details['nodes_no_content']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Nodes with no linked content
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            <p class="sbn-coverage-note">No entry in <code>sbn_skill_node_content</code>, no <code>voicing_categories</code>, no <code>content_tag_slug</code>. The Step A curation worklist.</p>
            @if(!$n)
                <p class="sbn-coverage-empty">✓ All nodes have linked content.</p>
            @else
            <div class="sbn-list-table-wrap">
                <table class="sbn-table">
                    <thead><tr><th>Branch</th><th>Title</th><th>Slug</th><th style="width:80px;"></th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td style="font-size:13px;color:var(--clr-text-dim);text-transform:capitalize;">{{ str_replace('-', ' ', $row->branch) }}</td>
                            <td>{{ $row->title }}</td>
                            <td><code style="font-size:12px;">{{ $row->slug }}</code></td>
                            <td><a href="{{ route('admin.skill-nodes.edit', $row->id) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </details>
</div>

<div id="nodes-no-course" class="sbn-coverage-section">
    @php $rows = $details['nodes_no_course']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Nodes with no course
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            @if(!$n)
                <p class="sbn-coverage-empty">✓ All nodes are linked to at least one course.</p>
            @else
            <div class="sbn-list-table-wrap">
                <table class="sbn-table">
                    <thead><tr><th>Branch</th><th>Title</th><th>Slug</th><th style="width:80px;"></th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td style="font-size:13px;color:var(--clr-text-dim);text-transform:capitalize;">{{ str_replace('-', ' ', $row->branch) }}</td>
                            <td>{{ $row->title }}</td>
                            <td><code style="font-size:12px;">{{ $row->slug }}</code></td>
                            <td><a href="{{ route('admin.skill-nodes.edit', $row->id) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </details>
</div>

<div id="nodes-no-style" class="sbn-coverage-section">
    @php $rows = $details['nodes_no_style']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Nodes with no style
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            <p class="sbn-coverage-note">Foundational nodes are legitimately style-neutral — this is informational, not an error.</p>
            @if(!$n)
                <p class="sbn-coverage-empty">✓ All nodes have at least one style tag.</p>
            @else
            <div class="sbn-list-table-wrap">
                <table class="sbn-table">
                    <thead><tr><th>Branch</th><th>Title</th><th>Slug</th><th style="width:80px;"></th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td style="font-size:13px;color:var(--clr-text-dim);text-transform:capitalize;">{{ str_replace('-', ' ', $row->branch) }}</td>
                            <td>{{ $row->title }}</td>
                            <td><code style="font-size:12px;">{{ $row->slug }}</code></td>
                            <td><a href="{{ route('admin.skill-nodes.edit', $row->id) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </details>
</div>

<div id="nodes-no-icon" class="sbn-coverage-section">
    @php $rows = $details['nodes_no_icon']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Nodes with no icon
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            @if(!$n)
                <p class="sbn-coverage-empty">✓ All nodes have an icon.</p>
            @else
            <div class="sbn-list-table-wrap">
                <table class="sbn-table">
                    <thead><tr><th>Branch</th><th>Title</th><th>Slug</th><th style="width:80px;"></th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td style="font-size:13px;color:var(--clr-text-dim);text-transform:capitalize;">{{ str_replace('-', ' ', $row->branch) }}</td>
                            <td>{{ $row->title }}</td>
                            <td><code style="font-size:12px;">{{ $row->slug }}</code></td>
                            <td><a href="{{ route('admin.skill-nodes.edit', $row->id) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </details>
</div>

{{-- ── CONTENT-SIDE GAPS ─────────────────────────────────────── --}}

<p class="sbn-coverage-group-label">Content-side gaps — linking worklist</p>
<p style="font-size:13px;color:var(--clr-text-dim);margin-bottom:12px;">Link these from the node editor (Content tab), not from the content editor.</p>

<div id="songs-unlinked" class="sbn-coverage-section">
    @php $rows = $details['songs_unlinked']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Songs unlinked to any node
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            @if(!$n)
                <p class="sbn-coverage-empty">✓ All songs are linked.</p>
            @else
            <div class="sbn-list-table-wrap">
                <table class="sbn-table">
                    <thead><tr><th>Title</th><th>Slug</th><th style="width:80px;"></th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td>{{ $row->title }}</td>
                            <td><code style="font-size:12px;">{{ $row->slug }}</code></td>
                            <td><a href="{{ route('admin.leadsheets.edit', $row->id) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </details>
</div>

<div id="rhythms-unlinked" class="sbn-coverage-section">
    @php $rows = $details['rhythms_unlinked']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Rhythms unlinked to any node
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            @if(!$n)
                <p class="sbn-coverage-empty">✓ All rhythms are linked.</p>
            @else
            <div class="sbn-list-table-wrap">
                <table class="sbn-table">
                    <thead><tr><th>Name</th><th>Slug</th><th style="width:80px;"></th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td>{{ $row->title }}</td>
                            <td><code style="font-size:12px;">{{ $row->slug }}</code></td>
                            <td><a href="{{ route('admin.rhythms.edit', $row->id) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </details>
</div>

<div id="progressions-unlinked" class="sbn-coverage-section">
    @php $rows = $details['progressions_unlinked']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Progressions unlinked to any node
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            @if(!$n)
                <p class="sbn-coverage-empty">✓ All progressions are linked.</p>
            @else
            <div class="sbn-list-table-wrap">
                <table class="sbn-table">
                    <thead><tr><th>Name</th><th>Slug</th><th style="width:80px;"></th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td>{{ $row->title }}</td>
                            <td><code style="font-size:12px;">{{ $row->slug }}</code></td>
                            <td><a href="{{ route('admin.progressions.edit', $row->id) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </details>
</div>

<div id="voicing-cats-no-node" class="sbn-coverage-section">
    @php $rows = $details['voicing_cats_no_node']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Voicing categories with no node
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            <p class="sbn-coverage-note">Distinct <code>voicing_category</code> values in <code>sbn_chord_diagrams</code> not claimed by any node's <code>voicing_categories</code> JSON.</p>
            @if(!$n)
                <p class="sbn-coverage-empty">✓ All voicing categories are covered by a node.</p>
            @else
            <div class="sbn-coverage-cats">
                @foreach($rows as $cat)
                <span class="sbn-coverage-cat-badge">{{ $cat }}</span>
                @endforeach
            </div>
            <div style="margin-top:12px;">
                <a href="{{ route('admin.skill-nodes.create') }}" class="sbn-btn sbn-btn-sm sbn-btn-primary">+ New Skill Node</a>
            </div>
            @endif
        </div>
    </details>
</div>

<div id="courses-no-node" class="sbn-coverage-section">
    @php $rows = $details['courses_no_node']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Courses with no node
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            @if(!$n)
                <p class="sbn-coverage-empty">✓ All courses are linked to a node.</p>
            @else
            <div class="sbn-list-table-wrap">
                <table class="sbn-table">
                    <thead><tr><th>Title</th><th>Slug</th><th style="width:80px;"></th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td>{{ $row->title }}</td>
                            <td><code style="font-size:12px;">{{ $row->slug }}</code></td>
                            <td><a href="{{ route('admin.courses.edit', $row->id) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </details>
</div>

{{-- ── PIPELINE ──────────────────────────────────────────────── --}}

<p class="sbn-coverage-group-label">Pipeline</p>

<div id="pending-drafts" class="sbn-coverage-section">
    @php $rows = $details['pending_drafts']; $n = count($rows); @endphp
    <details class="sbn-coverage-details" @if($n) open @endif>
        <summary>
            Pending voicing drafts
            <span class="sbn-coverage-count {{ $n ? 'sbn-coverage-count--warn' : 'sbn-coverage-count--ok' }}">{{ $n }}</span>
        </summary>
        <div class="sbn-coverage-body">
            @if(!$n)
                <p class="sbn-coverage-empty">✓ No pending drafts.</p>
            @else
            <div class="sbn-list-table-wrap">
                <table class="sbn-table">
                    <thead><tr><th>Leadsheet</th><th>Chord</th><th>Root</th><th>Created</th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td style="font-size:13px;">{{ $row->leadsheet_title }}</td>
                            <td>{{ $row->chord_name }}</td>
                            <td>{{ $row->root_note }}</td>
                            <td style="font-size:13px;color:var(--clr-text-dim);">{{ \Carbon\Carbon::parse($row->created_at)->diffForHumans() }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </details>
</div>

@endsection
