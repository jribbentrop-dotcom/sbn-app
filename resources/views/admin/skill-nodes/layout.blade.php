@extends('layouts.admin')

@section('title', 'Skill Tree Layout')

@section('actions')
    <a href="{{ route('admin.skill-nodes.index') }}" class="sbn-btn sbn-btn-secondary">← Back to nodes</a>
    <button type="button" id="skt-save" class="sbn-btn sbn-btn-primary">Save layout</button>
@endsection

@push('styles')
<style>
    .skt-toolbar {
        display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        margin-bottom: 12px; font-size: 13px; color: var(--clr-text-dim);
    }
    .skt-toolbar .skt-status { margin-left: auto; font-weight: 600; }
    .skt-status.is-dirty { color: var(--clr-danger); }
    .skt-status.is-saved { color: var(--clr-success); }

    .skt-canvas-wrap {
        position: relative; width: 100%;
        background: var(--clr-surface-2);
        border: 1px solid var(--clr-border);
        border-radius: 12px; overflow: hidden;
        /* keep the design-space aspect square-ish; 1000x1000 units */
        aspect-ratio: 1 / 1; max-height: 78vh;
    }
    .skt-edges { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
    #skt-arrow path { stroke: var(--clr-text-muted); }
    /* edge stroke colour/style, driven by class so it stays on-token */
    .skt-edges line.skt-edge-same  { stroke: var(--clr-text-muted); stroke-width: 1.4; }
    .skt-edges line.skt-edge-cross { stroke: var(--clr-primary); stroke-width: 2; stroke-dasharray: 6 5; }
    .skt-edges line.skt-edge-draw  { stroke: var(--clr-primary); stroke-width: 2; stroke-dasharray: 4 4; }
    /* real edges become clickable (to delete); the drag preview never is */
    .skt-edges line.skt-edge-hit { pointer-events: stroke; cursor: pointer; }
    .skt-edges line.skt-edge-draw { pointer-events: none; }
    /* hovering an edge signals it's clickable-to-delete (toggled from JS since
       the hit-line and visible line are separate SVG elements) */
    .skt-edges line.skt-edge-hover { stroke: var(--clr-danger) !important; stroke-width: 3 !important; }
    .skt-canvas-wrap.is-linking .skt-tile { cursor: crosshair; }
    .skt-tile.is-link-target .skt-tile-shape { box-shadow: 0 0 0 3px var(--clr-primary); }
    .skt-tier-lbl {
        position: absolute; left: 8px; font-size: 11px; font-weight: 600;
        color: var(--clr-text-muted); transform: translateY(-50%);
        pointer-events: none; user-select: none;
    }

    .skt-tile {
        position: absolute; width: 46px; height: 46px;
        transform: translate(-50%, -50%); cursor: grab;
        display: flex; align-items: center; justify-content: center;
        touch-action: none;
    }
    .skt-tile:active { cursor: grabbing; }
    .skt-tile-shape {
        width: 40px; height: 40px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        background: var(--clr-white); border: 2px solid var(--clr-text-muted);
        box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        transition: box-shadow 0.12s;
    }
    .skt-tile.is-dragging .skt-tile-shape { box-shadow: 0 4px 14px rgba(0,0,0,0.28); }
    .skt-tile svg { width: 20px; height: 20px; }
    .skt-tile-name {
        position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
        margin-top: 2px; white-space: nowrap; font-size: 10px;
        color: var(--clr-text-dim); pointer-events: none; user-select: none;
        max-width: 120px; overflow: hidden; text-overflow: ellipsis;
    }
    .skt-legend {
        display: flex; gap: 14px; flex-wrap: wrap; margin-top: 12px;
        font-size: 12px; color: var(--clr-text-dim);
    }
    .skt-legend span { display: inline-flex; align-items: center; gap: 5px; }
    .skt-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
</style>
@endpush

@section('content')

<div class="skt-toolbar">
    <span>Drag tiles to position them. <strong>Ctrl+drag</strong> from a prerequisite onto the skill it unlocks to link them; <strong>click an edge</strong> to remove it. Vertical = grade tier; colour = branch.</span>
    <span id="skt-status" class="skt-status">No changes</span>
</div>

<div class="skt-canvas-wrap" id="skt-canvas">
    <svg class="skt-edges" id="skt-edges" viewBox="0 0 1000 1000" preserveAspectRatio="none">
        <defs>
            <marker id="skt-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                <path d="M2 1L8 5L2 9" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </marker>
        </defs>
    </svg>
    {{-- tiles injected by JS --}}
</div>

<div class="skt-legend">
    <span><i class="skt-swatch" style="background:var(--clr-branch-harmony)"></i> Harmony</span>
    <span><i class="skt-swatch" style="background:var(--clr-branch-rhythm)"></i> Rhythm</span>
    <span><i class="skt-swatch" style="background:var(--clr-branch-melody)"></i> Melody</span>
    <span><i class="skt-swatch" style="background:var(--clr-branch-technique)"></i> Technique</span>
    <span><i class="skt-swatch" style="background:var(--clr-branch-reading-theory)"></i> Reading &amp; Theory</span>
    <span><i class="skt-swatch" style="background:var(--clr-branch-ear-training)"></i> Ear Training</span>
    <span style="color:var(--clr-text-muted)">— solid edge = same branch · dashed = cross-branch</span>
</div>

@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    const NODES = @json($nodes);
    const EDGES = @json($edges);
    const SAVE_URL = @json(route('admin.skill-nodes.saveLayout'));
    const EDGE_URL = @json(route('admin.skill-nodes.addEdge'));
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // Branch → colour (the tile colour encodes branch here; the student tree
    // will use style colour, but for the editor branch is the more useful key).
    // Sourced from the --clr-branch-* design tokens so the palette stays in one
    // place; the hex fallbacks only bite if a token fails to resolve.
    const css = getComputedStyle(document.documentElement);
    const token = (name, fallback) => css.getPropertyValue(name).trim() || fallback;
    const BRANCH_COLOR = {
        'harmony':        token('--clr-branch-harmony',        '#3b82f6'),
        'rhythm':         token('--clr-branch-rhythm',         '#f39c12'),
        'melody':         token('--clr-branch-melody',         '#10b981'),
        'ear-training':   token('--clr-branch-ear-training',   '#ec4899'),
        'technique':      token('--clr-branch-technique',      '#8b5cf6'),
        'reading-theory': token('--clr-branch-reading-theory', '#64748b'),
    };

    // Heroicon outline paths per branch (mirrors SkillIcon.vue BRANCH_ICONS).
    const BRANCH_ICON = {
        'harmony':        'M9 9V4.5M9 9l6-2.25M9 9a4.5 4.5 0 1 0 4.5 4.5V6.75M15 6.75V4.5m0 2.25a4.5 4.5 0 1 1-4.5 4.5',
        'rhythm':         'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'melody':         'M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z',
        'technique':      'M10.05 4.575a1.575 1.575 0 1 0-3.15 0v3m3.15-3v-1.5a1.575 1.575 0 0 1 3.15 0v1.5m-3.15 0 .075 5.925m3.075.75V4.575m0 0a1.575 1.575 0 0 1 3.15 0V15M6.9 7.575a1.575 1.575 0 1 0-3.15 0v8.175a6.75 6.75 0 0 0 6.75 6.75h2.018a5.25 5.25 0 0 0 3.712-1.538l1.732-1.732a5.25 5.25 0 0 0 1.538-3.712l.003-2.024a.668.668 0 0 1 .198-.471 1.575 1.575 0 1 0-2.228-2.228 3.818 3.818 0 0 0-1.12 2.687M6.9 7.575V12',
        'ear-training':   'M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z',
        'reading-theory': 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
    };

    const canvas = document.getElementById('skt-canvas');
    const svg = document.getElementById('skt-edges');
    const statusEl = document.getElementById('skt-status');
    const byId = new Map(NODES.map(n => [n.id, n]));

    // Working position state (design units 0..1000), seeded from the DB.
    const pos = new Map();
    NODES.forEach(n => pos.set(n.id, { x: n.pos_x ?? 500, y: n.pos_y ?? 500 }));

    let dirty = false;
    function setDirty(d) {
        dirty = d;
        statusEl.textContent = d ? 'Unsaved changes' : 'Saved';
        statusEl.className = 'skt-status ' + (d ? 'is-dirty' : 'is-saved');
    }

    // Transient message for edge add/remove (persisted server-side immediately,
    // so this is independent of the position dirty/save state). Restores the
    // position status afterwards.
    let flashTimer = null;
    function flash(msg, isError) {
        statusEl.textContent = msg;
        statusEl.className = 'skt-status ' + (isError ? 'is-dirty' : 'is-saved');
        clearTimeout(flashTimer);
        flashTimer = setTimeout(() => {
            statusEl.textContent = dirty ? 'Unsaved changes' : 'No changes';
            statusEl.className = 'skt-status' + (dirty ? ' is-dirty' : '');
        }, 2600);
    }

    // ── Tier labels (one per distinct grade row) ───────────────────────────────
    const tierY = new Map(); // grade → y (design units), from seeded data
    NODES.forEach(n => { if (n.grade) tierY.set(n.grade, n.pos_y); });
    [...tierY.entries()].forEach(([g, y]) => {
        const lbl = document.createElement('div');
        lbl.className = 'skt-tier-lbl';
        lbl.textContent = 'G' + g;
        lbl.style.top = (y / 1000 * 100) + '%';
        canvas.appendChild(lbl);
    });

    // ── Tiles ──────────────────────────────────────────────────────────────────
    const tiles = new Map();
    NODES.forEach(n => {
        const tile = document.createElement('div');
        tile.className = 'skt-tile';
        tile.dataset.id = n.id;
        const color = BRANCH_COLOR[n.branch] || '#888';
        const iconPath = BRANCH_ICON[n.branch] || BRANCH_ICON.harmony;
        tile.innerHTML =
            '<div class="skt-tile-shape" style="border-color:' + color + '">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="' + color + '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="' + iconPath + '"/></svg>' +
            '</div>' +
            '<span class="skt-tile-name">' + escapeHtml(n.title) + '</span>';
        canvas.appendChild(tile);
        tiles.set(n.id, tile);
        placeTile(n.id);
        attachDrag(tile, n.id);
    });

    function placeTile(id) {
        const t = tiles.get(id), p = pos.get(id);
        t.style.left = (p.x / 1000 * 100) + '%';
        t.style.top  = (p.y / 1000 * 100) + '%';
    }

    // ── Edges ────────────────────────────────────────────────────────────────────
    // Each edge = { from: dependent id, to: prerequisite id }. The drawn line runs
    // from the prerequisite (to) up to the dependent (from); arrowhead at the dependent.
    const SVGNS = 'http://www.w3.org/2000/svg';
    const edgeEls = [];
    const edgeKeys = new Set(); // "from>to" dedup
    const edgeKey = (from, to) => from + '>' + to;

    function addEdgeEl(from, to) {
        if (edgeKeys.has(edgeKey(from, to))) return null;
        const fromN = byId.get(from), toN = byId.get(to);
        if (!fromN || !toN) return null;
        const crossBranch = fromN.branch !== toN.branch;

        // A wide, invisible hit-line under the visible one so clicking to delete
        // is forgiving (SVG hairlines are near-impossible to click precisely).
        const hit = document.createElementNS(SVGNS, 'line');
        hit.setAttribute('class', 'skt-edge-hit');
        hit.setAttribute('stroke', 'transparent');
        hit.setAttribute('stroke-width', '12');

        const line = document.createElementNS(SVGNS, 'line');
        line.setAttribute('class', crossBranch ? 'skt-edge-cross' : 'skt-edge-same');
        line.setAttribute('marker-end', 'url(#skt-arrow)');
        line.style.pointerEvents = 'none';

        svg.appendChild(hit);
        svg.appendChild(line);

        const rec = { el: line, hit, from, to };
        edgeEls.push(rec);
        edgeKeys.add(edgeKey(from, to));

        hit.addEventListener('click', () => deleteEdge(rec));
        hit.addEventListener('pointerenter', () => line.classList.add('skt-edge-hover'));
        hit.addEventListener('pointerleave', () => line.classList.remove('skt-edge-hover'));
        hit.setAttribute('title', 'Click to remove this link');
        positionEdge(rec);
        return rec;
    }

    function positionEdge(rec) {
        const a = pos.get(rec.to);   // prerequisite (line start)
        const b = pos.get(rec.from); // dependent (line end / arrow)
        if (!a || !b) return;
        for (const seg of [rec.el, rec.hit]) {
            seg.setAttribute('x1', a.x); seg.setAttribute('y1', a.y);
            seg.setAttribute('x2', b.x); seg.setAttribute('y2', b.y);
        }
    }

    function redrawEdges() { edgeEls.forEach(positionEdge); }

    EDGES.forEach(e => addEdgeEl(e.from, e.to));
    redrawEdges();

    async function deleteEdge(rec) {
        const fromN = byId.get(rec.from), toN = byId.get(rec.to);
        if (!confirm(`Remove link: “${toN.title}” → “${fromN.title}”?`)) return;
        try {
            const res = await fetch(EDGE_URL, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ from: rec.from, requires: rec.to }),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            rec.el.remove(); rec.hit.remove();
            edgeKeys.delete(edgeKey(rec.from, rec.to));
            const i = edgeEls.indexOf(rec);
            if (i > -1) edgeEls.splice(i, 1);
            flash('Link removed', false);
        } catch (err) {
            flash('Delete failed — ' + err.message, true);
        }
    }

    // ── Linking (Ctrl+drag prerequisite → dependent) ─────────────────────────────
    // Shared across tiles: only one link gesture at a time. The source tile is the
    // PREREQUISITE (drag start); the tile released on is the DEPENDENT.
    let linking = null; // { sourceId, preview, hoverTile }

    function pointToDesign(ev) {
        const rect = canvas.getBoundingClientRect();
        return {
            x: clamp((ev.clientX - rect.left) / rect.width * 1000, 0, 1000),
            y: clamp((ev.clientY - rect.top) / rect.height * 1000, 0, 1000),
        };
    }

    function startLink(ev, sourceId) {
        const preview = document.createElementNS(SVGNS, 'line');
        preview.setAttribute('class', 'skt-edge-draw');
        preview.setAttribute('marker-end', 'url(#skt-arrow)');
        const a = pos.get(sourceId);
        preview.setAttribute('x1', a.x); preview.setAttribute('y1', a.y);
        preview.setAttribute('x2', a.x); preview.setAttribute('y2', a.y);
        svg.appendChild(preview);
        linking = { sourceId, preview, hoverTile: null };
        canvas.classList.add('is-linking');
    }

    function moveLink(ev) {
        const p = pointToDesign(ev);
        linking.preview.setAttribute('x2', p.x);
        linking.preview.setAttribute('y2', p.y);
        // highlight the tile currently under the cursor (as the drop target)
        const el = document.elementFromPoint(ev.clientX, ev.clientY);
        const overTile = el && el.closest('.skt-tile');
        if (overTile !== linking.hoverTile) {
            linking.hoverTile?.classList.remove('is-link-target');
            const overId = overTile ? Number(overTile.dataset.id) : null;
            if (overTile && overId !== linking.sourceId) {
                overTile.classList.add('is-link-target');
                linking.hoverTile = overTile;
            } else {
                linking.hoverTile = null;
            }
        }
    }

    async function endLink(ev) {
        const { sourceId, preview, hoverTile } = linking;
        preview.remove();
        hoverTile?.classList.remove('is-link-target');
        canvas.classList.remove('is-linking');
        linking = null;

        const el = document.elementFromPoint(ev.clientX, ev.clientY);
        const targetTile = el && el.closest('.skt-tile');
        if (!targetTile) return;
        const targetId = Number(targetTile.dataset.id); // dependent
        if (targetId === sourceId) return;
        if (edgeKeys.has(edgeKey(targetId, sourceId))) { flash('Already linked', false); return; }

        try {
            const res = await fetch(EDGE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ from: targetId, requires: sourceId }),
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok) { flash(body.error || ('Link failed — HTTP ' + res.status), true); return; }
            addEdgeEl(targetId, sourceId); // from=dependent, to=prerequisite
            flash(`Linked “${byId.get(sourceId).title}” → “${byId.get(targetId).title}”`, false);
        } catch (err) {
            flash('Link failed — ' + err.message, true);
        }
    }

    // ── Drag (pointer events, design-unit math) ─────────────────────────────────
    function attachDrag(tile, id) {
        let startX, startY, origX, origY;

        tile.addEventListener('pointerdown', (ev) => {
            ev.preventDefault();
            // Ctrl/⌘ + drag = draw a prerequisite edge instead of moving the tile.
            if (ev.ctrlKey || ev.metaKey) {
                tile.setPointerCapture(ev.pointerId);
                tile.dataset.linking = '1';
                startLink(ev, id);
                return;
            }
            tile.setPointerCapture(ev.pointerId);
            tile.classList.add('is-dragging');
            startX = ev.clientX; startY = ev.clientY;
            origX = pos.get(id).x; origY = pos.get(id).y;
        });

        tile.addEventListener('pointermove', (ev) => {
            if (tile.dataset.linking === '1' && linking) { moveLink(ev); return; }
            if (!tile.classList.contains('is-dragging')) return;
            const rect = canvas.getBoundingClientRect();
            // px delta → design-unit delta (canvas is 1000x1000 design units)
            const dx = (ev.clientX - startX) / rect.width * 1000;
            const dy = (ev.clientY - startY) / rect.height * 1000;
            const p = pos.get(id);
            p.x = clamp(Math.round(origX + dx), 0, 1000);
            p.y = clamp(Math.round(origY + dy), 0, 1000);
            placeTile(id);
            redrawEdges();
            if (!dirty) setDirty(true);
        });

        const end = (ev) => {
            if (tile.dataset.linking === '1') {
                delete tile.dataset.linking;
                try { tile.releasePointerCapture(ev.pointerId); } catch (_) {}
                if (linking) endLink(ev);
                return;
            }
            if (tile.classList.contains('is-dragging')) {
                tile.classList.remove('is-dragging');
                try { tile.releasePointerCapture(ev.pointerId); } catch (_) {}
            }
        };
        tile.addEventListener('pointerup', end);
        tile.addEventListener('pointercancel', end);
    }

    // ── Save ─────────────────────────────────────────────────────────────────────
    document.getElementById('skt-save').addEventListener('click', async () => {
        const positions = [...pos.entries()].map(([id, p]) => ({ id, x: p.x, y: p.y }));
        statusEl.textContent = 'Saving…'; statusEl.className = 'skt-status';
        try {
            const res = await fetch(SAVE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ positions }),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            setDirty(false);
        } catch (err) {
            statusEl.textContent = 'Save failed — ' + err.message;
            statusEl.className = 'skt-status is-dirty';
        }
    });

    // Warn on navigating away with unsaved changes.
    window.addEventListener('beforeunload', (e) => {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    }
})();
</script>
@endpush
