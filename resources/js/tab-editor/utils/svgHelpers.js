/**
 * SBN Tab Editor — SVG Helpers
 *
 * Pure functions that generate SVG markup strings for tab notation elements.
 * Ported from Alpine's _tabBeams, _tabTies, _tabFlag, _tabRest methods.
 *
 * These return SVG element strings to be inserted inside <svg> elements.
 */

import { SMUFL, LAYOUT, baseDuration, flagCount as calcFlagCount, flagGlyph, restGlyph, isDotted, getX, stringY } from './constants.js';

// ── Flags ──────────────────────────────────────────────────

export function renderFlag(x, stemEndY, stemDir, count, voice) {
    const vc = voice === 2 ? ' voice-2' : '';
    const fontSize = 28;
    const glyph = flagGlyph(stemDir, count);
    return `<text x="${x}" y="${stemEndY}" font-family="Bravura" font-size="${fontSize}" fill="#333" class="sbn-tab-flag smufl${vc}">${glyph}</text>`;
}

// ── Rests ──────────────────────────────────────────────────

export function renderRest(x, ticks, voice, id) {
    const vc = voice === 2 ? ' voice-2' : '';
    const glyph = restGlyph(ticks);
    const b = baseDuration(ticks);
    let y, fontSize, baseline;

    if (b >= 1920) {
        // Whole rest: sits below 2nd line
        y = LAYOUT.stringAreaTop + 1 * LAYOUT.stringSpacing;
        fontSize = 22;
        baseline = 'auto';
    } else if (b >= 960) {
        // Half rest: sits on 3rd line
        y = LAYOUT.stringAreaTop + 2 * LAYOUT.stringSpacing;
        fontSize = 22;
        baseline = 'auto';
    } else {
        // Quarter and smaller: centered
        y = LAYOUT.stringAreaTop + 2.5 * LAYOUT.stringSpacing;
        fontSize = b >= 480 ? 26 : 22;
        baseline = 'central';
    }

    return `<text x="${x}" y="${y}" font-family="Bravura" font-size="${fontSize}" dominant-baseline="${baseline}" text-anchor="middle" fill="#333" class="sbn-tab-rest smufl${vc}" ${id ? `data-event-id="${id}"` : ''}>${glyph}</text>`;
}

// ── Tie arcs ───────────────────────────────────────────────

/**
 * Render a tie arc as a quadratic bezier.
 * Used for both same-measure and cross-measure ties.
 * Cross-measure ties simply have x2 > measureWidth — they bleed
 * past the barline via overflow:visible on the SVG.
 */
/**
 * Render a tie arc using an SVG elliptical arc command.
 * Arc commands render cleaner than quadratic beziers in most browsers
 * (no anti-aliasing shadow artefact).
 */
function tieArc(x1, x2, y, dir, voice) {
    const vc = voice === 2 ? ' voice-2' : '';
    const span = Math.abs(x2 - x1);
    const rx = span / 2;
    // Outer arc: controls overall curve height
    const ryOuter = Math.min(Math.max(span * 0.18, 4), 10);
    // Inner arc: slightly shallower — creates lens thickness (thick middle, tapered ends)
    const ryInner = Math.max(ryOuter - 2, 1.5);
    // Outer sweeps away from notes; inner sweeps back — forming a closed lens
    const sweepOuter = dir === 'up' ? 1 : 0;
    const sweepInner = dir === 'up' ? 0 : 1;
    const d = `M ${x1} ${y} A ${rx} ${ryOuter} 0 0 ${sweepOuter} ${x2} ${y} A ${rx} ${ryInner} 0 0 ${sweepInner} ${x1} ${y} Z`;
    return `<path d="${d}" fill="#333" stroke="none" class="sbn-tab-tie${vc}"/>`;
}

// ── Beams ──────────────────────────────────────────────────

export function renderBeams(measureEvents, getXFn) {
    let html = '';

    // Find all beam groups in this measure
    const processed = new Set();
    const beamGroups = [];

    measureEvents.forEach(ev => {
        // Skip rests for non-tuplet beam groups, but tuplet rests must anchor their group
        // (e.g. when the first event in a triplet is a rest, it still carries tupletBracket).
        const isTupletRest = ev.isRest && ev.tupletActual;
        if ((ev.isRest && !isTupletRest) || !ev.beamWith || processed.has(ev.id)) return;
        const group = ev.beamWith.filter(g => g.measureIdx === ev.measureIdx);
        if (group.length < 2) return;
        group.forEach(g => processed.add(g.id));
        beamGroups.push(group);
    });

    beamGroups.forEach(bg => {
        if (bg.length < 2) return;
        const stemDir  = bg[0].stemDir || 'down';
        const x1       = getXFn(bg[0].xPos);
        const x2       = getXFn(bg[bg.length - 1].xPos);
        const vc       = bg[0].voice === 2 ? ' voice-2' : '';
        // noBeamBar: quarter-triplet groups use stems only — no connecting beam rect
        const noBeamBar = !!bg[0].noBeamBar;

        // Stem base and tip
        let baseY, tipY;
        if (stemDir === 'up') {
            baseY = LAYOUT.topStringY - LAYOUT.stemBaseOffset;
            tipY  = baseY - LAYOUT.stemLength;
        } else {
            baseY = LAYOUT.bottomStringY + LAYOUT.stemBaseOffset;
            tipY  = baseY + LAYOUT.stemLength;
        }

        // Draw stems for each note in the group (skip rests — they don't have stems)
        bg.forEach(n => {
            if (n.isRest) return;
            const x = getXFn(n.xPos);
            html += `<line x1="${x}" y1="${baseY}" x2="${x}" y2="${tipY}" class="sbn-tab-stem${vc}"/>`;
        });

        if (!noBeamBar) {
            // For tuplet groups, draw the beam bar across the full group span (first to last
            // event, including rests) as long as there is at least 1 non-rest note.
            // This handles freshly-created triplets where events 2+3 are rests.
            // For regular non-tuplet groups, require at least 2 non-rest notes.
            const notesOnly = bg.filter(n => !n.isRest);
            const isTupletGroup = !!bg[0].tupletActual;
            const canDrawBeam = isTupletGroup ? notesOnly.length >= 1 : notesOnly.length >= 2;
            if (canDrawBeam) {
            // Span from first to last member of the group (not just notes)
            const spanFirst = isTupletGroup ? bg[0] : notesOnly[0];
            const spanLast  = isTupletGroup ? bg[bg.length - 1] : notesOnly[notesOnly.length - 1];
            const bx1 = getXFn(spanFirst.xPos);
            const bx2 = getXFn(spanLast.xPos);
            // Primary beam (8th-note level) — flush with stem tips
            const primaryBeamY = stemDir === 'down'
                ? tipY - LAYOUT.beamThickness
                : tipY;
            html += `<rect x="${bx1}" y="${primaryBeamY}" width="${bx2 - bx1}" height="${LAYOUT.beamThickness}" fill="#333" class="sbn-tab-beam${vc}"/>`;

            // Secondary beam (16th-note level)
            let maxFlags = 0;
            notesOnly.forEach(n => { const f = calcFlagCount(n.ticks); if (f > maxFlags) maxFlags = f; });

            if (maxFlags >= 2) {
                const beam2Y = stemDir === 'down'
                    ? primaryBeamY - LAYOUT.interBeamGap - LAYOUT.beamThickness
                    : primaryBeamY + LAYOUT.beamThickness + LAYOUT.interBeamGap;

                // Only use the explicit begin/continue/end path when those values
                // are actually present. MuseScore emits 'forward hook' / 'backward
                // hook' for isolated 16ths inside a mixed-duration beam group, which
                // never forms a begin/end pair — fall through to the adjacency-based
                // partial-beam logic in that case.
                const hasBeam2 = bg.some(n => n.beam2 === 'begin' || n.beam2 === 'end');

                if (hasBeam2) {
                    let b2Group = [];
                    bg.forEach(n => {
                        if (n.beam2 === 'begin') {
                            b2Group = [n];
                        } else if (n.beam2 === 'continue' && b2Group.length) {
                            b2Group.push(n);
                        } else if (n.beam2 === 'end' && b2Group.length) {
                            b2Group.push(n);
                            const bx1 = getXFn(b2Group[0].xPos);
                            const bx2 = getXFn(b2Group[b2Group.length - 1].xPos);
                            html += `<rect x="${bx1}" y="${beam2Y}" width="${bx2 - bx1}" height="${LAYOUT.beamThickness}" fill="#333" class="sbn-tab-beam-2${vc}"/>`;
                            b2Group = [];
                        }
                    });
                } else {
                    const PARTIAL_BEAM_LEN = 6;

                    bg.forEach((n, i) => {
                        const is16 = calcFlagCount(n.ticks) >= 2;
                        if (!is16) return;

                        const prevIs16 = i > 0 && calcFlagCount(bg[i - 1].ticks) >= 2;
                        const nextIs16 = i < bg.length - 1 && calcFlagCount(bg[i + 1].ticks) >= 2;

                        if (prevIs16 || nextIs16) {
                            if (nextIs16) {
                                const bx1 = getXFn(n.xPos);
                                const bx2 = getXFn(bg[i + 1].xPos);
                                html += `<rect x="${bx1}" y="${beam2Y}" width="${bx2 - bx1}" height="${LAYOUT.beamThickness}" fill="#333" class="sbn-tab-beam-2${vc}"/>`;
                            }
                        } else {
                            const nx = getXFn(n.xPos);
                            if (i < bg.length - 1) {
                                html += `<rect x="${nx}" y="${beam2Y}" width="${PARTIAL_BEAM_LEN}" height="${LAYOUT.beamThickness}" fill="#333" class="sbn-tab-beam-2${vc}"/>`;
                            } else {
                                html += `<rect x="${nx - PARTIAL_BEAM_LEN}" y="${beam2Y}" width="${PARTIAL_BEAM_LEN}" height="${LAYOUT.beamThickness}" fill="#333" class="sbn-tab-beam-2${vc}"/>`;
                            }
                        }
                    });
                }
            }
            } // end canDrawBeam
        } // end !noBeamBar

        // ── Tuplet bracket / number ──────────────────────────────
        // Rendered for ALL tuplet groups — beamed or stem-only.
        // tupletBracket=true  → horizontal bracket with vertical drops + "3"
        // tupletBracket=false → just the italic "3" (beamed groups don't need the bracket)
        if (bg[0].tupletActual === 3) {
            const cx = (x1 + x2) / 2;

            // For beamed groups: place relative to the beam/tip.
            // For stem-only (noBeamBar) groups: place relative to tip directly.
            // stems-down → label goes above the downward stem tip (lower SVG Y = higher visually)
            // stems-up   → label goes below the upward stem tip
            let bracketY, labelY;
            if (stemDir === 'down') {
                // stems-down: tip is BELOW the strings (large Y).
                // Label must go further below — outside the stem, not inside it.
                bracketY = tipY + 6;
                labelY   = bracketY + 8;
            } else {
                // stems-up: tip is ABOVE the strings (small/negative Y).
                // Label must go further above — outside the stem.
                bracketY = tipY - 6;
                labelY   = bracketY - 2;
            }

            const useBracket = noBeamBar ? bg[0].tupletBracket : false;
            // Beamed groups never need the bracket (beams themselves show the grouping)

            if (useBracket) {
                const dropLen = 5;
                const extend = 3;
                const y1b = bracketY;
                // Drops point TOWARD the strings (system), opposite to stem direction:
                // stems-down → bracket is below tip → drops go UP (smaller Y)
                // stems-up   → bracket is above tip → drops go DOWN (larger Y)
                const y2b = stemDir === 'down' ? bracketY - dropLen : bracketY + dropLen;
                const numGap = 5;
                html += `<line x1="${x1 - extend}" y1="${y1b}" x2="${x1 - extend}" y2="${y2b}" stroke="#000000" stroke-width="0.8" class="sbn-tab-tuplet-bracket${vc}"/>`;
                html += `<line x1="${x1 - extend}" y1="${y1b}" x2="${cx - numGap}" y2="${y1b}" stroke="#000000" stroke-width="0.8" class="sbn-tab-tuplet-bracket${vc}"/>`;
                html += `<line x1="${cx + numGap}" y1="${y1b}" x2="${x2 + extend}" y2="${y1b}" stroke="#000000" stroke-width="0.8" class="sbn-tab-tuplet-bracket${vc}"/>`;
                html += `<line x1="${x2 + extend}" y1="${y1b}" x2="${x2 + extend}" y2="${y2b}" stroke="#000000" stroke-width="0.8" class="sbn-tab-tuplet-bracket${vc}"/>`;
                html += `<text x="${cx}" y="${y1b}" dominant-baseline="central" text-anchor="middle" font-size="9" font-style="italic" fill="#000000" class="sbn-tab-tuplet${vc}">3</text>`;
            } else {
                html += `<text x="${cx}" y="${labelY - 5}" text-anchor="middle" font-size="8" font-style="italic" fill="#000000" class="sbn-tab-tuplet${vc}">3</text>`;
            }
        }
    });

    return html;
}

// ── Ties ───────────────────────────────────────────────────

/**
 * Render all ties that START in this measure.
 *
 * Same-measure ties: full arc from note to note within this measure.
 * Cross-measure ties: full arc from note to target note in the NEXT measure.
 *   The target X is computed as measureWidth + targetXInNextMeasure.
 *   The arc bleeds past the barline via overflow:visible on the SVG element.
 *   Only the source measure renders the tie — no incoming arcs needed.
 *
 * @param {Array}    measureEvents    — events in this measure
 * @param {number}   globalMeasureIdx — this measure's global index
 * @param {Function} getXFn           — xPos → pixel X for this measure
 * @param {number}   measureWidth     — effective pixel width of this measure
 * @param {Function} getNextXFn       — xPos → pixel X for the NEXT measure (or null)
 * @param {number}   nextMeasureWidth — effective pixel width of next measure (or 0)
 */
export function renderTies(measureEvents, globalMeasureIdx, getXFn, measureWidth, getNextXFn, nextMeasureWidth) {
    let html = '';

    measureEvents.forEach(ev => {
        if (ev.isRest || !ev.notes.length) return;

        const voice = ev.voice || 1;
        const dir = ev.stemDir === 'up' ? 'down' : 'up';

        ev.notes.forEach(note => {
            if (!note.tieStart || !note.tieEndEvent) return;

            const y = stringY(note.string);
            const x1 = getXFn(ev.xPos) + 6;

            if (note.tieEndEvent.measureIdx === globalMeasureIdx) {
                // Same-measure tie — target is in this measure
                const x2 = getXFn(note.tieEndEvent.xPos) - 6;
                html += tieArc(x1, x2, y, dir, voice);
            } else if (getNextXFn && note.tieEndNote) {
                // Cross-measure tie — compute target X in next measure's space,
                // then offset by this measure's width so it bleeds past the barline
                const targetLocalX = getNextXFn(note.tieEndEvent.xPos) - 6;
                const x2 = measureWidth + targetLocalX;
                html += tieArc(x1, x2, y, dir, voice);
            } else {
                // Fallback: no next measure info — arc to barline edge
                const x2 = measureWidth + 15;
                html += tieArc(x1, x2, y, dir, voice);
            }
        });
    });

    return html;
}

// ── Repeat barlines ────────────────────────────────────────

export function renderRepeatStart(sT, sB, sH) {
    const dR = sH * 0.03;
    const rY1 = sT + sH * 1.5 / 5;
    const rY2 = sT + sH * 3.5 / 5;
    let html = '';
    html += `<line x1="2" y1="${sT}" x2="2" y2="${sB}" stroke="#000" stroke-width="${(sH * 0.066).toFixed(2)}" stroke-linecap="butt"/>`;
    html += `<line x1="6" y1="${sT}" x2="6" y2="${sB}" stroke="#000" stroke-width="${(sH * 0.021).toFixed(2)}" stroke-linecap="butt"/>`;
    html += `<circle cx="11" cy="${rY1.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`;
    html += `<circle cx="11" cy="${rY2.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`;
    return html;
}

export function renderRepeatEnd(measureWidth, sT, sB, sH) {
    const dR = sH * 0.03;
    const rY1 = sT + sH * 1.5 / 5;
    const rY2 = sT + sH * 3.5 / 5;
    let html = '';
    html += `<line x1="${measureWidth - 6}" y1="${sT}" x2="${measureWidth - 6}" y2="${sB}" stroke="#000" stroke-width="${(sH * 0.021).toFixed(2)}" stroke-linecap="butt"/>`;
    html += `<line x1="${measureWidth - 2}" y1="${sT}" x2="${measureWidth - 2}" y2="${sB}" stroke="#000" stroke-width="${(sH * 0.066).toFixed(2)}" stroke-linecap="butt"/>`;
    html += `<circle cx="${measureWidth - 11}" cy="${rY1.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`;
    html += `<circle cx="${measureWidth - 11}" cy="${rY2.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`;
    return html;
}
