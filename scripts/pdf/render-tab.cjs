/**
 * render-tab.cjs
 *
 * Standalone Node renderer for a single TAB measure or a sequence of measures.
 * Ports the SVG-generation logic from TabMeasure.vue + svgHelpers.js + constants.js.
 *
 * Usage (single measure JSON on stdin):
 *   echo '{"measures":[...], "timeSig":"4/4", "barsPerRow":4}' | node render-tab.cjs
 *
 * Or from PHP via Process::run with JSON piped to stdin.
 * Outputs one SVG string wrapping all measures in a row to stdout.
 *
 * Input JSON schema:
 * {
 *   "measures": [MeasureObject, ...],  // array of measure data (see TabMeasure props)
 *   "timeSig": "4/4",
 *   "barsPerRow": 4,
 *   "showChordNames": true
 * }
 *
 * MeasureObject mirrors the tab_xml / LeadsheetParser output:
 * {
 *   "index": 0,
 *   "events": [EventObject, ...],
 *   "chordNames": ["Dm7"],
 *   "repeatStart": false, "repeatEnd": false,
 *   "volta": null,
 *   "pickupBeats": null,
 *   "actualTicks": null
 * }
 *
 * EventObject:
 * {
 *   "id": "te_1_abc",
 *   "tick": 0, "tickInMeasure": 0,
 *   "ticks": 480,      // duration in ticks
 *   "xPos": 0.0,       // 0..1 fractional position within measure
 *   "isRest": false,
 *   "voice": 1,
 *   "stemDir": "down", // "up"|"down"|null
 *   "flagCount": 0,
 *   "notes": [{ "string": 4, "fret": 5, "tieStart": false, "tieEndEvent": null }],
 *   "beamWith": null,  // or array
 *   "beamStart": false, "beamContinue": false, "beamEnd": false,
 *   "noBeamBar": false,
 *   "tupletActual": null,
 *   "tupletBracket": false
 * }
 */

'use strict';

// ── Constants (ported from constants.js) ──────────────────────────────────────

const SMUFL = {
    flag8thUp:    '',
    flag16thUp:   '',
    flag8thDown:  '',
    flag16thDown: '',
    restWhole:    '',
    restHalf:     '',
    restQuarter:  '',
    rest8th:      '',
    rest16th:     '',
    rest32nd:     '',
};

const LAYOUT = {
    stringCount:    6,
    measuresPerRow: 4,
    measureWidth:   160,
    stringSpacing:  10,
    topPadding:     25,
    bottomPadding:  23,
    stringAreaTop:  5,
    xPadding:       11,
    xPaddingFirst:  22,
    stemBaseOffset: 8,
    stemLength:     15,
    beamThickness:  3.2,
    interBeamGap:   2,
    noteFontSize:   12,
};
LAYOUT.tabHeight     = LAYOUT.topPadding + LAYOUT.stringSpacing * (LAYOUT.stringCount - 1) + LAYOUT.bottomPadding;
LAYOUT.topStringY    = LAYOUT.stringAreaTop;
LAYOUT.bottomStringY = LAYOUT.stringAreaTop + 5 * LAYOUT.stringSpacing;
LAYOUT.xRange        = LAYOUT.measureWidth - 2 * LAYOUT.xPadding;

// Chord-name strip height above the SVG
const CHORD_BAR_H = 16;

function baseDuration(ticks) {
    if (ticks === 1440) return 960;
    if (ticks === 720)  return 480;
    if (ticks === 360)  return 240;
    if (ticks === 180)  return 120;
    if (ticks === 640)  return 960;
    if (ticks === 320)  return 240;
    if (ticks === 160)  return 160;
    if (ticks === 80)   return 80;
    return ticks;
}

function calcFlagCount(ticks) {
    const b = baseDuration(ticks);
    if (b <= 60)  return 3;
    if (b <= 120) return 2;
    if (b <= 240) return 1;
    return 0;
}

function flagGlyph(stemDir, count) {
    if (stemDir === 'up') return count >= 2 ? SMUFL.flag16thUp : SMUFL.flag8thUp;
    return count >= 2 ? SMUFL.flag16thDown : SMUFL.flag8thDown;
}

function restGlyph(ticks) {
    if (ticks === 640) return SMUFL.restHalf;
    if (ticks === 320) return SMUFL.restQuarter;
    if (ticks === 160) return SMUFL.rest8th;
    if (ticks === 80)  return SMUFL.rest16th;
    const b = baseDuration(ticks);
    if (b >= 1920) return SMUFL.restWhole;
    if (b >= 960)  return SMUFL.restHalf;
    if (b >= 480)  return SMUFL.restQuarter;
    if (b >= 240)  return SMUFL.rest8th;
    if (b >= 120)  return SMUFL.rest16th;
    return SMUFL.rest32nd;
}

function isDotted(ticks) { return [1440, 720, 360, 180].includes(ticks); }

function stringY(stringNum) {
    return LAYOUT.stringAreaTop + (stringNum - 1) * LAYOUT.stringSpacing;
}

// ── SVG Helpers (ported from svgHelpers.js) ───────────────────────────────────

function renderFlag(x, stemEndY, stemDir, count, voice) {
    const vc = voice === 2 ? ' voice-2' : '';
    const glyph = flagGlyph(stemDir, count);
    return `<text x="${x}" y="${stemEndY}" font-family="Bravura" font-size="28" fill="#333" class="sbn-tab-flag smufl${vc}">${glyph}</text>`;
}

function renderRest(x, ticks, voice, id) {
    const vc = voice === 2 ? ' voice-2' : '';
    const glyph = restGlyph(ticks);
    const b = baseDuration(ticks);
    let y, fontSize, baseline;
    if (b >= 1920) {
        y = LAYOUT.stringAreaTop + 1 * LAYOUT.stringSpacing; fontSize = 22; baseline = 'auto';
    } else if (b >= 960) {
        y = LAYOUT.stringAreaTop + 2 * LAYOUT.stringSpacing; fontSize = 22; baseline = 'auto';
    } else {
        y = LAYOUT.stringAreaTop + 2.5 * LAYOUT.stringSpacing;
        fontSize = b >= 480 ? 26 : 22;
        baseline = 'central';
    }
    return `<text x="${x}" y="${y}" font-family="Bravura" font-size="${fontSize}" dominant-baseline="${baseline}" text-anchor="middle" fill="#333" class="sbn-tab-rest smufl${vc}"${id ? ` data-event-id="${id}"` : ''}>${glyph}</text>`;
}

function tieArc(x1, x2, y, dir, voice) {
    const vc = voice === 2 ? ' voice-2' : '';
    const span = Math.abs(x2 - x1);
    const rx = span / 2;
    const ryOuter = Math.min(Math.max(span * 0.18, 4), 10);
    const ryInner = Math.max(ryOuter - 2, 1.5);
    const sweepOuter = dir === 'up' ? 1 : 0;
    const sweepInner = dir === 'up' ? 0 : 1;
    const d = `M ${x1} ${y} A ${rx} ${ryOuter} 0 0 ${sweepOuter} ${x2} ${y} A ${rx} ${ryInner} 0 0 ${sweepInner} ${x1} ${y} Z`;
    return `<path d="${d}" fill="#333" stroke="none" class="sbn-tab-tie${vc}"/>`;
}

function renderBeams(measureEvents, getXFn) {
    let html = '';
    const processed = new Set();
    const beamGroups = [];

    measureEvents.forEach(ev => {
        const isTupletRest = ev.isRest && ev.tupletActual;
        if ((ev.isRest && !isTupletRest) || !ev.beamWith || processed.has(ev.id)) return;
        const group = ev.beamWith.filter(g => g.measureIdx === ev.measureIdx);
        if (group.length < 2) return;
        group.forEach(g => processed.add(g.id));
        beamGroups.push(group);
    });

    beamGroups.forEach(bg => {
        if (bg.length < 2) return;
        const stemDir   = bg[0].stemDir || 'down';
        const x1        = getXFn(bg[0].xPos);
        const x2        = getXFn(bg[bg.length - 1].xPos);
        const vc        = bg[0].voice === 2 ? ' voice-2' : '';
        const noBeamBar = !!bg[0].noBeamBar;

        let baseY, tipY;
        if (stemDir === 'up') {
            baseY = LAYOUT.topStringY - LAYOUT.stemBaseOffset;
            tipY  = baseY - LAYOUT.stemLength;
        } else {
            baseY = LAYOUT.bottomStringY + LAYOUT.stemBaseOffset;
            tipY  = baseY + LAYOUT.stemLength;
        }

        bg.forEach(n => {
            if (n.isRest) return;
            const x = getXFn(n.xPos);
            html += `<line x1="${x}" y1="${baseY}" x2="${x}" y2="${tipY}" class="sbn-tab-stem${vc}"/>`;
        });

        if (!noBeamBar) {
            const notesOnly = bg.filter(n => !n.isRest);
            const isTupletGroup = !!bg[0].tupletActual;
            const canDrawBeam = isTupletGroup ? notesOnly.length >= 1 : notesOnly.length >= 2;
            if (canDrawBeam) {
                const spanFirst = isTupletGroup ? bg[0] : notesOnly[0];
                const spanLast  = isTupletGroup ? bg[bg.length - 1] : notesOnly[notesOnly.length - 1];
                const bx1 = getXFn(spanFirst.xPos);
                const bx2 = getXFn(spanLast.xPos);
                const primaryBeamY = stemDir === 'down'
                    ? tipY - LAYOUT.beamThickness
                    : tipY;
                html += `<rect x="${bx1}" y="${primaryBeamY}" width="${bx2 - bx1}" height="${LAYOUT.beamThickness}" fill="#333" class="sbn-tab-beam${vc}"/>`;

                let maxFlags = 0;
                notesOnly.forEach(n => { const f = calcFlagCount(n.ticks); if (f > maxFlags) maxFlags = f; });

                if (maxFlags >= 2) {
                    const beam2Y = stemDir === 'down'
                        ? primaryBeamY - LAYOUT.interBeamGap - LAYOUT.beamThickness
                        : primaryBeamY + LAYOUT.beamThickness + LAYOUT.interBeamGap;

                    const hasBeam2 = bg.some(n => n.beam2 === 'begin' || n.beam2 === 'end');
                    if (hasBeam2) {
                        let b2Group = [];
                        bg.forEach(n => {
                            if (n.beam2 === 'begin')    { b2Group = [n]; }
                            else if (n.beam2 === 'continue' && b2Group.length) { b2Group.push(n); }
                            else if (n.beam2 === 'end' && b2Group.length) {
                                b2Group.push(n);
                                const bx1 = getXFn(b2Group[0].xPos);
                                const bx2 = getXFn(b2Group[b2Group.length - 1].xPos);
                                html += `<rect x="${bx1}" y="${beam2Y}" width="${bx2 - bx1}" height="${LAYOUT.beamThickness}" fill="#333" class="sbn-tab-beam-2${vc}"/>`;
                                b2Group = [];
                            }
                        });
                    } else {
                        const PARTIAL = 6;
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
                                html += i < bg.length - 1
                                    ? `<rect x="${nx}" y="${beam2Y}" width="${PARTIAL}" height="${LAYOUT.beamThickness}" fill="#333" class="sbn-tab-beam-2${vc}"/>`
                                    : `<rect x="${nx - PARTIAL}" y="${beam2Y}" width="${PARTIAL}" height="${LAYOUT.beamThickness}" fill="#333" class="sbn-tab-beam-2${vc}"/>`;
                            }
                        });
                    }
                }
            }
        }

        if (bg[0].tupletActual === 3) {
            const cx = (x1 + x2) / 2;
            let bracketY, labelY;
            if (stemDir === 'down') { bracketY = tipY + 6; labelY = bracketY + 8; }
            else                    { bracketY = tipY - 6; labelY = bracketY - 2; }
            const useBracket = noBeamBar ? bg[0].tupletBracket : false;
            if (useBracket) {
                const drop = 5, ext = 3, numGap = 5;
                const y2b = stemDir === 'down' ? bracketY - drop : bracketY + drop;
                html += `<line x1="${x1 - ext}" y1="${bracketY}" x2="${x1 - ext}" y2="${y2b}" stroke="#000" stroke-width="0.8"/>`;
                html += `<line x1="${x1 - ext}" y1="${bracketY}" x2="${cx - numGap}" y2="${bracketY}" stroke="#000" stroke-width="0.8"/>`;
                html += `<line x1="${cx + numGap}" y1="${bracketY}" x2="${x2 + ext}" y2="${bracketY}" stroke="#000" stroke-width="0.8"/>`;
                html += `<line x1="${x2 + ext}" y1="${bracketY}" x2="${x2 + ext}" y2="${y2b}" stroke="#000" stroke-width="0.8"/>`;
                html += `<text x="${cx}" y="${bracketY}" dominant-baseline="central" text-anchor="middle" font-size="9" font-style="italic" fill="#000">3</text>`;
            } else {
                html += `<text x="${cx}" y="${labelY - 5}" text-anchor="middle" font-size="8" font-style="italic" fill="#000">3</text>`;
            }
        }
    });

    return html;
}

function renderTies(measureEvents, globalMeasureIdx, getXFn, measureWidth, getNextXFn, nextMeasureWidth) {
    let html = '';
    measureEvents.forEach(ev => {
        if (ev.isRest || !ev.notes.length) return;
        const voice = ev.voice || 1;
        const dir = ev.stemDir === 'up' ? 'down' : 'up';
        ev.notes.forEach(note => {
            if (!note.tieStart || !note.tieEndEvent) return;
            const y  = stringY(note.string);
            const x1 = getXFn(ev.xPos) + 6;
            if (note.tieEndEvent.measureIdx === globalMeasureIdx) {
                html += tieArc(x1, getXFn(note.tieEndEvent.xPos) - 6, y, dir, voice);
            } else if (getNextXFn && note.tieEndNote) {
                html += tieArc(x1, measureWidth + getNextXFn(note.tieEndEvent.xPos) - 6, y, dir, voice);
            } else {
                html += tieArc(x1, measureWidth + 15, y, dir, voice);
            }
        });
    });
    return html;
}

function renderRepeatStart(sT, sB, sH) {
    const dR = sH * 0.03;
    const rY1 = sT + sH * 1.5 / 5, rY2 = sT + sH * 3.5 / 5;
    return `<line x1="2" y1="${sT}" x2="2" y2="${sB}" stroke="#000" stroke-width="${(sH*0.066).toFixed(2)}" stroke-linecap="butt"/>`
         + `<line x1="6" y1="${sT}" x2="6" y2="${sB}" stroke="#000" stroke-width="${(sH*0.021).toFixed(2)}" stroke-linecap="butt"/>`
         + `<circle cx="11" cy="${rY1.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`
         + `<circle cx="11" cy="${rY2.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`;
}

function renderRepeatEnd(w, sT, sB, sH) {
    const dR = sH * 0.03;
    const rY1 = sT + sH * 1.5 / 5, rY2 = sT + sH * 3.5 / 5;
    return `<line x1="${w-6}" y1="${sT}" x2="${w-6}" y2="${sB}" stroke="#000" stroke-width="${(sH*0.021).toFixed(2)}" stroke-linecap="butt"/>`
         + `<line x1="${w-2}" y1="${sT}" x2="${w-2}" y2="${sB}" stroke="#000" stroke-width="${(sH*0.066).toFixed(2)}" stroke-linecap="butt"/>`
         + `<circle cx="${w-11}" cy="${rY1.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`
         + `<circle cx="${w-11}" cy="${rY2.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`;
}

// ── Measure renderer (ported from TabMeasure svgContent computed) ─────────────

function renderMeasureSvg(m, opts) {
    const { barsPerRow = 4, ticksPerMeasure = 1920, isFirstOfSection = false, nextMeasure = null, isNextFirstOfSection = false } = opts;

    const standardTotalWidth = LAYOUT.measureWidth * (LAYOUT.measuresPerRow || 4);
    const baseWidth = standardTotalWidth / Math.max(1, barsPerRow);
    const actualTicks = m.actualTicks || 0;
    const widthRatio  = actualTicks > ticksPerMeasure ? actualTicks / ticksPerMeasure : 1;
    const w = baseWidth * widthRatio;

    const sT = LAYOUT.stringAreaTop;
    const sB = LAYOUT.bottomStringY;
    const sH = sB - sT;

    function getXm(xPos) {
        const xL   = isFirstOfSection ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
        const xRng = w - xL - LAYOUT.xPadding;
        return xL + xPos * xRng;
    }

    function getNextXm(xPos) {
        if (!nextMeasure) {
            const xL   = isNextFirstOfSection ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
            const xRng = baseWidth - xL - LAYOUT.xPadding;
            return xL + xPos * xRng;
        }
        const nextActual = nextMeasure.actualTicks || 0;
        const nextRatio  = nextActual > ticksPerMeasure ? nextActual / ticksPerMeasure : 1;
        const nextW = baseWidth * nextRatio;
        const xL   = isNextFirstOfSection ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
        const xRng = nextW - xL - LAYOUT.xPadding;
        return xL + xPos * xRng;
    }

    const nextEffectiveWidth = nextMeasure
        ? baseWidth * (nextMeasure.actualTicks > ticksPerMeasure ? nextMeasure.actualTicks / ticksPerMeasure : 1)
        : baseWidth;

    const events = m.events || [];
    let inner = '';

    // Volta bracket
    if (m.volta) {
        const vy = sT - 35;
        const vx1 = -2, vx2 = m.voltaEnd ? w - 2 : w;
        if (m.voltaStart) inner += `<line x1="${vx1}" y1="${vy}" x2="${vx1}" y2="${sT - 20}" stroke="#000" stroke-width="0.8"/>`;
        inner += `<line x1="${vx1}" y1="${vy}" x2="${vx2 - 3}" y2="${vy}" stroke="#000" stroke-width="0.8"/>`;
        if (m.voltaEnd) inner += `<line x1="${vx2 - 3}" y1="${vy}" x2="${vx2 - 3}" y2="${sT - 20}" stroke="#000" stroke-width="0.8"/>`;
        if (m.voltaStart) inner += `<text x="${vx1 + 3}" y="${vy + 11}" font-family="sans" font-weight="900" font-size="12" fill="#000">${m.volta.text || (m.volta.number + '.')}</text>`;
    }

    // String lines
    for (let s = 0; s < LAYOUT.stringCount; s++) {
        const y = LAYOUT.stringAreaTop + s * LAYOUT.stringSpacing;
        inner += `<line x1="0" y1="${y}" x2="${w}" y2="${y}" stroke="#ccc" stroke-width="0.5"/>`;
    }

    if (m.repeatStart) inner += renderRepeatStart(sT, sB, sH);
    inner += renderBeams(events, getXm);
    inner += renderTies(events, m.index, getXm, w, nextMeasure ? getNextXm : null, nextEffectiveWidth);

    events.forEach(ev => {
        if (!ev.isRest) return;
        inner += renderRest(getXm(ev.xPos), ev.ticks, ev.voice, ev.id);
    });

    events.forEach(ev => {
        if (ev.isRest || !ev.notes.length) return;
        const vc = ev.voice === 2 ? ' voice-2' : '';
        ev.notes.forEach(note => {
            if (note.string == null || note.fret == null) return;
            const x = getXm(ev.xPos);
            const y = stringY(note.string);
            inner += `<text x="${x}" y="${y}" dominant-baseline="central" text-anchor="middle" font-size="${LAYOUT.noteFontSize}" fill="#1a1a2e" data-event-id="${ev.id}">${note.fret}</text>`;
        });

        if (ev.stemDir) {
            const x = getXm(ev.xPos);
            let sY1, sY2;
            if (ev.stemDir === 'up') {
                sY1 = LAYOUT.topStringY - LAYOUT.stemBaseOffset;
                sY2 = sY1 - LAYOUT.stemLength;
            } else {
                sY1 = LAYOUT.bottomStringY + LAYOUT.stemBaseOffset;
                sY2 = sY1 + LAYOUT.stemLength;
            }
            const handledByBeams = ev.beamWith || ev.beamStart || ev.beamContinue || ev.beamEnd || ev.noBeamBar;
            if (!handledByBeams) {
                inner += `<line x1="${x}" y1="${sY1}" x2="${x}" y2="${sY2}" stroke="#333" stroke-width="0.8"/>`;
                if (ev.flagCount > 0) inner += renderFlag(x, sY2, ev.stemDir, ev.flagCount, ev.voice);
            }
            if (isDotted(ev.ticks)) {
                const dY = ev.stemDir === 'up' ? sY2 + 4 : sY2 - 4;
                inner += `<circle cx="${x + 4}" cy="${dY}" r="1.2" fill="#333"/>`;
            }
        }
    });

    if (m.repeatEnd) {
        inner += renderRepeatEnd(w, sT, sB, sH);
    } else {
        inner += `<line x1="${w - 0.5}" y1="${sT}" x2="${w - 0.5}" y2="${sB}" stroke="#333" stroke-width="0.8"/>`;
    }

    return { svg: inner, width: w };
}

// ── Row renderer — wraps multiple measures into one SVG ───────────────────────

function renderTabRow(input) {
    const {
        measures     = [],
        timeSig      = '4/4',
        barsPerRow   = 4,
        showChordNames = true,
    } = input;

    const [beatsStr, beatTypeStr] = timeSig.split('/');
    const beats    = parseInt(beatsStr) || 4;
    const beatType = parseInt(beatTypeStr) || 4;
    const tpm      = 480 * (4 / beatType) * beats;

    const standardTotalWidth = LAYOUT.measureWidth * (LAYOUT.measuresPerRow || 4);
    const baseWidth = standardTotalWidth / Math.max(1, barsPerRow);

    // Total SVG width = sum of all effective measure widths
    let totalWidth = 0;
    const widths = measures.map(m => {
        const actual = m.actualTicks || 0;
        const ratio  = actual > tpm ? actual / tpm : 1;
        return baseWidth * ratio;
    });
    widths.forEach(w => totalWidth += w);

    const chordBarH  = showChordNames ? CHORD_BAR_H : 0;
    const totalHeight = chordBarH + LAYOUT.tabHeight;

    let svgBody = '';
    let xOffset = 0;

    measures.forEach((m, i) => {
        const isFirst     = i === 0;
        const nextMeasure = measures[i + 1] ?? null;
        const isNextFirst = false; // all in same row

        const { svg: inner, width: mw } = renderMeasureSvg(m, {
            barsPerRow,
            ticksPerMeasure: tpm,
            isFirstOfSection: isFirst,
            nextMeasure,
            isNextFirstOfSection: isNextFirst,
        });

        // Chord names above
        if (showChordNames && m.chordNames && m.chordNames.length) {
            const total = m.chordNames.length;
            m.chordNames.forEach((name, ci) => {
                const xFrac = ci / total;
                const xPx   = xOffset + (isFirst ? LAYOUT.xPaddingFirst : LAYOUT.xPadding) + xFrac * (mw - (isFirst ? LAYOUT.xPaddingFirst : LAYOUT.xPadding) - LAYOUT.xPadding);
                svgBody += `<text x="${xPx.toFixed(1)}" y="${chordBarH - 3}" font-family="Crimson Text,Georgia,serif" font-style="italic" font-size="11" fill="#2c3e50">${escapeXml(name)}</text>`;
            });
        }

        svgBody += `<g transform="translate(${xOffset.toFixed(1)}, ${chordBarH})">${inner}</g>`;
        xOffset += mw;
    });

    // Opening barline
    svgBody = `<line x1="0.5" y1="${chordBarH + LAYOUT.stringAreaTop}" x2="0.5" y2="${chordBarH + LAYOUT.bottomStringY}" stroke="#333" stroke-width="0.8"/>` + svgBody;

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${totalWidth.toFixed(1)} ${totalHeight}" width="${totalWidth.toFixed(1)}" height="${totalHeight}" style="overflow:visible">${svgBody}</svg>`;
}

function escapeXml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── Entry point ───────────────────────────────────────────────────────────────
// Accepts either:
//   node render-tab.cjs <path-to-json-file>
//   node render-tab.cjs --stdin   (reads from stdin, for piping)

const fs = require('fs');
const path = require('path');

function run(raw) {
    let input;
    try {
        input = JSON.parse(raw);
    } catch (e) {
        process.stderr.write('render-tab.cjs: invalid JSON input\n');
        process.exit(1);
    }
    process.stdout.write(renderTabRow(input));
}

const arg = process.argv[2];
if (!arg || arg === '--stdin') {
    let raw = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', chunk => { raw += chunk; });
    process.stdin.on('end', () => run(raw));
} else {
    run(fs.readFileSync(path.resolve(arg), 'utf8'));
}
