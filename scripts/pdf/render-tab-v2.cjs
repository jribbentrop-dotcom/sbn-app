/**
 * render-tab-v2.cjs
 *
 * Clean port of TabMeasure.vue + svgHelpers.js + constants.js to Node/CommonJS.
 * All SVG attributes are inlined (no CSS class dependencies) so output is
 * self-contained for PDF rendering.
 *
 * Usage:
 *   node render-tab-v2.cjs <path-to-json-file>
 *
 * Input JSON: { measures, timeSig, barsPerRow, showChordNames }
 * Output: SVG string to stdout.
 */

'use strict';

// ── constants.js ──────────────────────────────────────────────────────────────

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

const CHORD_BAR_H     = 20;   // space for chord name text only
const DIAGRAM_H       = 68;   // height of an embedded diagram (scaled from 88×76 cropped viewBox)
const DIAGRAM_W       = 78;   // width of an embedded diagram

function baseDuration(ticks) {
    switch (ticks) {
        case 1440: return 960;
        case 720:  return 480;
        case 360:  return 240;
        case 180:  return 120;
        case 640:  return 960;
        case 320:  return 240;
        case 160:  return 160;
        case 80:   return 80;
        default:   return ticks;
    }
}

function flagCount(ticks) {
    const b = baseDuration(ticks);
    if (b <= 60)  return 3;
    if (b <= 120) return 2;
    if (b <= 240) return 1;
    return 0;
}

function isDotted(ticks) {
    return [1440, 720, 360, 180].includes(ticks);
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

function flagGlyph(stemDir, count) {
    if (stemDir === 'up') return count >= 2 ? SMUFL.flag16thUp : SMUFL.flag8thUp;
    return count >= 2 ? SMUFL.flag16thDown : SMUFL.flag8thDown;
}

function stringY(stringNum) {
    return LAYOUT.stringAreaTop + (stringNum - 1) * LAYOUT.stringSpacing;
}

// ── svgHelpers.js ─────────────────────────────────────────────────────────────

function renderFlag(x, stemEndY, stemDir, count) {
    const glyph = flagGlyph(stemDir, count);
    return `<text x="${x}" y="${stemEndY}" font-family="Bravura" font-size="28" fill="#333">${glyph}</text>`;
}

function renderRest(x, ticks, id) {
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
    const idAttr = id ? ` data-event-id="${id}"` : '';
    return `<text x="${x}" y="${y}" font-family="Bravura" font-size="${fontSize}" dominant-baseline="${baseline}" text-anchor="middle" fill="#333"${idAttr}>${glyph}</text>`;
}

function tieArc(x1, x2, y, dir) {
    const span = Math.abs(x2 - x1);
    const rx = span / 2;
    const ryOuter = Math.min(Math.max(span * 0.18, 4), 10);
    const ryInner = Math.max(ryOuter - 2, 1.5);
    const sweepOuter = dir === 'up' ? 1 : 0;
    const sweepInner = dir === 'up' ? 0 : 1;
    const d = `M ${x1} ${y} A ${rx} ${ryOuter} 0 0 ${sweepOuter} ${x2} ${y} A ${rx} ${ryInner} 0 0 ${sweepInner} ${x1} ${y} Z`;
    return `<path d="${d}" fill="#333" stroke="none"/>`;
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
        const stemDir  = bg[0].stemDir || 'down';
        const noBeamBar = !!bg[0].noBeamBar;

        let baseY, tipY;
        if (stemDir === 'up') {
            baseY = LAYOUT.topStringY - LAYOUT.stemBaseOffset;
            tipY  = baseY - LAYOUT.stemLength;
        } else {
            baseY = LAYOUT.bottomStringY + LAYOUT.stemBaseOffset;
            tipY  = baseY + LAYOUT.stemLength;
        }

        // Stems
        bg.forEach(n => {
            if (n.isRest) return;
            const x = getXFn(n.xPos);
            html += `<line x1="${x}" y1="${baseY}" x2="${x}" y2="${tipY}" stroke="#333" stroke-width="1" stroke-linecap="butt"/>`;
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
                const primaryBeamY = stemDir === 'down' ? tipY - LAYOUT.beamThickness : tipY;
                html += `<rect x="${bx1}" y="${primaryBeamY}" width="${bx2 - bx1}" height="${LAYOUT.beamThickness}" fill="#333"/>`;

                let maxFlags = 0;
                notesOnly.forEach(n => { const f = flagCount(n.ticks); if (f > maxFlags) maxFlags = f; });

                if (maxFlags >= 2) {
                    const beam2Y = stemDir === 'down'
                        ? primaryBeamY - LAYOUT.interBeamGap - LAYOUT.beamThickness
                        : primaryBeamY + LAYOUT.beamThickness + LAYOUT.interBeamGap;

                    const hasBeam2 = bg.some(n => n.beam2 === 'begin' || n.beam2 === 'end');
                    if (hasBeam2) {
                        let b2Group = [];
                        bg.forEach(n => {
                            if (n.beam2 === 'begin')                         { b2Group = [n]; }
                            else if (n.beam2 === 'continue' && b2Group.length) { b2Group.push(n); }
                            else if (n.beam2 === 'end' && b2Group.length) {
                                b2Group.push(n);
                                const bx1 = getXFn(b2Group[0].xPos);
                                const bx2 = getXFn(b2Group[b2Group.length - 1].xPos);
                                html += `<rect x="${bx1}" y="${beam2Y}" width="${bx2 - bx1}" height="${LAYOUT.beamThickness}" fill="#333"/>`;
                                b2Group = [];
                            }
                        });
                    } else {
                        const PARTIAL = 6;
                        bg.forEach((n, i) => {
                            const is16 = flagCount(n.ticks) >= 2;
                            if (!is16) return;
                            const prevIs16 = i > 0 && flagCount(bg[i - 1].ticks) >= 2;
                            const nextIs16 = i < bg.length - 1 && flagCount(bg[i + 1].ticks) >= 2;
                            if (prevIs16 || nextIs16) {
                                if (nextIs16) {
                                    html += `<rect x="${getXFn(n.xPos)}" y="${beam2Y}" width="${getXFn(bg[i + 1].xPos) - getXFn(n.xPos)}" height="${LAYOUT.beamThickness}" fill="#333"/>`;
                                }
                            } else {
                                const nx = getXFn(n.xPos);
                                html += i < bg.length - 1
                                    ? `<rect x="${nx}" y="${beam2Y}" width="${PARTIAL}" height="${LAYOUT.beamThickness}" fill="#333"/>`
                                    : `<rect x="${nx - PARTIAL}" y="${beam2Y}" width="${PARTIAL}" height="${LAYOUT.beamThickness}" fill="#333"/>`;
                            }
                        });
                    }
                }
            }
        }

        // Tuplet bracket / number
        if (bg[0].tupletActual === 3) {
            const x1 = getXFn(bg[0].xPos);
            const x2 = getXFn(bg[bg.length - 1].xPos);
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

function renderTies(measureEvents, globalMeasureIdx, getXFn, measureWidth, getNextXFn) {
    let html = '';
    measureEvents.forEach(ev => {
        if (ev.isRest || !ev.notes.length) return;
        const dir = ev.stemDir === 'up' ? 'down' : 'up';
        ev.notes.forEach(note => {
            if (!note.tieStart || !note.tieEndEvent) return;
            const y  = stringY(note.string);
            const x1 = getXFn(ev.xPos) + 6;
            if (note.tieEndEvent.measureIdx === globalMeasureIdx) {
                html += tieArc(x1, getXFn(note.tieEndEvent.xPos) - 6, y, dir);
            } else if (getNextXFn && note.tieEndNote) {
                html += tieArc(x1, measureWidth + getNextXFn(note.tieEndEvent.xPos) - 6, y, dir);
            } else {
                html += tieArc(x1, measureWidth + 15, y, dir);
            }
        });
    });
    return html;
}

function renderRepeatStart(sT, sB, sH) {
    const dR = sH * 0.03;
    const rY1 = sT + sH * 1.5 / 5;
    const rY2 = sT + sH * 3.5 / 5;
    return `<line x1="2" y1="${sT}" x2="2" y2="${sB}" stroke="#000" stroke-width="${(sH * 0.066).toFixed(2)}" stroke-linecap="butt"/>`
         + `<line x1="6" y1="${sT}" x2="6" y2="${sB}" stroke="#000" stroke-width="${(sH * 0.021).toFixed(2)}" stroke-linecap="butt"/>`
         + `<circle cx="11" cy="${rY1.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`
         + `<circle cx="11" cy="${rY2.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`;
}

function renderRepeatEnd(w, sT, sB, sH) {
    const dR = sH * 0.03;
    const rY1 = sT + sH * 1.5 / 5;
    const rY2 = sT + sH * 3.5 / 5;
    return `<line x1="${w - 6}" y1="${sT}" x2="${w - 6}" y2="${sB}" stroke="#000" stroke-width="${(sH * 0.021).toFixed(2)}" stroke-linecap="butt"/>`
         + `<line x1="${w - 2}" y1="${sT}" x2="${w - 2}" y2="${sB}" stroke="#000" stroke-width="${(sH * 0.066).toFixed(2)}" stroke-linecap="butt"/>`
         + `<circle cx="${w - 11}" cy="${rY1.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`
         + `<circle cx="${w - 11}" cy="${rY2.toFixed(2)}" r="${dR.toFixed(2)}" fill="#000"/>`;
}

// ── Measure renderer (from TabMeasure.vue svgContent computed) ────────────────

function renderMeasureSvg(m, opts) {
    const {
        barsPerRow       = 4,
        ticksPerMeasure  = 1920,
        isFirstOfSection = false,
        nextMeasure      = null,
        isNextFirstOfSection = false,
    } = opts;

    const standardTotalWidth = LAYOUT.measureWidth * (LAYOUT.measuresPerRow || 4);
    const baseWidth = standardTotalWidth / Math.max(1, barsPerRow);
    const actual    = m.actualTicks || 0;
    const ratio     = actual > ticksPerMeasure ? actual / ticksPerMeasure : 1;
    const w         = baseWidth * ratio;

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
        const nextW      = baseWidth * nextRatio;
        const xL         = isNextFirstOfSection ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
        const xRng       = nextW - xL - LAYOUT.xPadding;
        return xL + xPos * xRng;
    }

    const nextEffectiveWidth = nextMeasure
        ? baseWidth * (((nextMeasure.actualTicks || 0) > ticksPerMeasure ? (nextMeasure.actualTicks || 0) / ticksPerMeasure : 1))
        : baseWidth;

    const events = m.events || [];
    let html = '';

    // Volta bracket
    if (m.volta) {
        const vy  = sT - 35;
        const vx1 = -2;
        const vx2 = m.voltaEnd ? w - 2 : w;
        if (m.voltaStart) html += `<line x1="${vx1}" y1="${vy}" x2="${vx1}" y2="${sT - 20}" stroke="#000" stroke-width="0.8" stroke-linecap="square"/>`;
        html += `<line x1="${vx1}" y1="${vy}" x2="${vx2 - 3}" y2="${vy}" stroke="#000" stroke-width="0.8" stroke-linecap="square"/>`;
        if (m.voltaEnd) html += `<line x1="${vx2 - 3}" y1="${vy}" x2="${vx2 - 3}" y2="${sT - 20}" stroke="#000" stroke-width="0.8" stroke-linecap="square"/>`;
        if (m.voltaStart) html += `<text x="${vx1 + 3}" y="${vy + 11}" font-family="sans" font-weight="900" font-size="12" fill="#000">${m.volta.text || (m.volta.number + '.')}</text>`;
    }

    // String lines
    for (let s = 0; s < LAYOUT.stringCount; s++) {
        const y = LAYOUT.stringAreaTop + s * LAYOUT.stringSpacing;
        html += `<line x1="0" y1="${y}" x2="${w}" y2="${y}" stroke="#404040" stroke-width="0.6"/>`;
    }

    if (m.repeatStart) html += renderRepeatStart(sT, sB, sH);
    html += renderBeams(events, getXm);
    html += renderTies(events, m.index, getXm, w, nextMeasure ? getNextXm : null, nextEffectiveWidth);

    // Rests
    events.forEach(ev => {
        if (!ev.isRest) return;
        html += renderRest(getXm(ev.xPos), ev.ticks, ev.id);
    });

    // Notes + standalone stems + flags + dots
    events.forEach(ev => {
        if (ev.isRest || !ev.notes.length) return;

        ev.notes.forEach(note => {
            if (note.string == null || note.fret == null) return;
            const x = getXm(ev.xPos);
            const y = stringY(note.string);
            html += `<text x="${x}" y="${y}" dominant-baseline="central" text-anchor="middle" font-family="Crimson Text,Georgia,serif" font-size="13" font-weight="900" fill="#222" stroke="#fff" stroke-width="3" stroke-linejoin="round" paint-order="stroke fill" data-event-id="${ev.id}">${note.fret}</text>`;
        });

        if (ev.stemDir) {
            const x = getXm(ev.xPos);
            let sY1, sY2;
            if (ev.stemDir === 'up') {
                sY1 = LAYOUT.topStringY  - LAYOUT.stemBaseOffset;
                sY2 = sY1 - LAYOUT.stemLength;
            } else {
                sY1 = LAYOUT.bottomStringY + LAYOUT.stemBaseOffset;
                sY2 = sY1 + LAYOUT.stemLength;
            }
            const handledByBeams = ev.beamWith || ev.beamStart || ev.beamContinue || ev.beamEnd || ev.noBeamBar;
            if (!handledByBeams) {
                html += `<line x1="${x}" y1="${sY1}" x2="${x}" y2="${sY2}" stroke="#333" stroke-width="1" stroke-linecap="butt"/>`;
                if (ev.flagCount > 0) html += renderFlag(x, sY2, ev.stemDir, ev.flagCount);
            }
            if (isDotted(ev.ticks)) {
                const dY = ev.stemDir === 'up' ? sY2 + 4 : sY2 - 4;
                html += `<circle cx="${x + 4}" cy="${dY}" r="1.2" fill="#333"/>`;
            }
        }
    });

    if (m.repeatEnd) {
        html += renderRepeatEnd(w, sT, sB, sH);
    } else {
        html += `<line x1="${w - 0.5}" y1="${sT}" x2="${w - 0.5}" y2="${sB}" stroke="#333" stroke-width="1.2"/>`;
    }

    return { svg: html, width: w };
}

// ── Row renderer ──────────────────────────────────────────────────────────────

function renderTabRow(input) {
    const {
        measures       = [],
        timeSig        = '4/4',
        barsPerRow     = 4,
        showChordNames = true,
        voicings       = {},   // { chordName: svgDataUri } — diagrams shown on first occurrence
    } = input;

    const hasDiagrams = showChordNames && Object.keys(voicings).length > 0;

    const [beatsStr, beatTypeStr] = timeSig.split('/');
    const beats    = parseInt(beatsStr) || 4;
    const beatType = parseInt(beatTypeStr) || 4;
    const tpm      = 480 * (4 / beatType) * beats;

    const standardTotalWidth = LAYOUT.measureWidth * (LAYOUT.measuresPerRow || 4);
    const baseWidth = standardTotalWidth / Math.max(1, barsPerRow);

    let totalWidth = 0;
    measures.forEach(m => {
        const actual = m.actualTicks || 0;
        const ratio  = actual > tpm ? actual / tpm : 1;
        totalWidth  += baseWidth * ratio;
    });

    // Layout stack (top→bottom): chord name text | diagrams | TAB staff
    const chordBarH   = showChordNames ? CHORD_BAR_H : 0;
    const diagBarH    = hasDiagrams ? DIAGRAM_H + 4 : 0;
    const totalHeader = chordBarH + diagBarH;
    const totalHeight = totalHeader + LAYOUT.tabHeight;

    let body    = '';
    let xOffset = 0;

    // Pre-pass: compute x positions and collect diagrams to draw
    const diagsToDraw = [];  // { name, x, uri }
    const seenThisRow = new Set();
    let xOff2 = 0;
    measures.forEach((m, i) => {
        const isFirst = i === 0;
        const actual  = m.actualTicks || 0;
        const ratio   = actual > tpm ? actual / tpm : 1;
        const mw      = baseWidth * ratio;
        if (showChordNames && m.chordNames && m.chordNames.length) {
            const total = m.chordNames.length;
            m.chordNames.forEach((name, ci) => {
                // Normalise: strip slash bass for diagram lookup
                const baseName = name.split('/')[0];
                if (!seenThisRow.has(name) && voicings[name]) {
                    seenThisRow.add(name);
                    const xL  = isFirst ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
                    const xRng = mw - xL - LAYOUT.xPadding;
                    const xPx = xOff2 + xL + (ci / total) * xRng;
                    diagsToDraw.push({ name, x: xPx, svgMarkup: voicings[name] });
                }
            });
        }
        xOff2 += mw;
    });

    // Draw diagrams below chord names; record diagram centre x per chord name for label alignment
    const diagCentreX = {};
    diagsToDraw.forEach(({ name, x, svgMarkup }) => {
        const dx = Math.max(0, Math.min(x - DIAGRAM_W / 2, totalWidth - DIAGRAM_W));
        diagCentreX[name] = dx + DIAGRAM_W / 2;
        body += `<svg x="${dx.toFixed(1)}" y="${chordBarH}" width="${DIAGRAM_W}" height="${DIAGRAM_H}" viewBox="0 0 88 76" preserveAspectRatio="xMidYMid meet">${svgMarkup}</svg>`;
    });

    // Draw chord names and TAB measures
    xOffset = 0;
    measures.forEach((m, i) => {
        const isFirst    = i === 0;
        const nextM      = measures[i + 1] ?? null;

        const { svg: inner, width: mw } = renderMeasureSvg(m, {
            barsPerRow,
            ticksPerMeasure:      tpm,
            isFirstOfSection:     isFirst,
            nextMeasure:          nextM,
            isNextFirstOfSection: false,
        });

        if (showChordNames && m.chordNames && m.chordNames.length) {
            const total = m.chordNames.length;
            m.chordNames.forEach((name, ci) => {
                const xL   = isFirst ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
                const xRng = mw - xL - LAYOUT.xPadding;
                const beatX = xOffset + xL + (ci / total) * xRng;
                // If this chord has a diagram on this row, centre label over it
                const xPx = (diagCentreX[name] !== undefined) ? diagCentreX[name] : beatX;
                body += formatChordSvg(name, xPx.toFixed(1), chordBarH - 3);
            });
        }

        body   += `<g transform="translate(${xOffset.toFixed(1)},${totalHeader})">${inner}</g>`;
        xOffset += mw;
    });

    // Opening barline
    const openBarY1 = totalHeader + LAYOUT.stringAreaTop;
    const openBarY2 = totalHeader + LAYOUT.bottomStringY;
    body = `<line x1="0.5" y1="${openBarY1}" x2="0.5" y2="${openBarY2}" stroke="#333" stroke-width="1.2"/>` + body;

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${totalWidth.toFixed(1)} ${totalHeight}" width="${totalWidth.toFixed(1)}" height="${totalHeight}" style="overflow:visible">${body}</svg>`;
}

// Parse a chord name string into { root, accidental, quality, ext, bass }
// matching the same decomposition as sbnFormatChord / ChordName helper.
function parseChordName(name) {
    // Strip slash bass first
    let bass = '', core = name;
    const slashIdx = name.lastIndexOf('/');
    if (slashIdx > 0) {
        bass = name.slice(slashIdx + 1);
        core = name.slice(0, slashIdx);
    }
    // Root: letter + optional accidental
    const rootMatch = core.match(/^([A-G])([#b♯♭]?)/);
    if (!rootMatch) return { root: name, accidental: '', quality: '', ext: '', bass };
    const root = rootMatch[1];
    const acc  = rootMatch[2].replace('#', '♯').replace('b', '♭');
    const rest = core.slice(rootMatch[0].length);
    // Quality vs extension: quality = leading letters (m, maj, dim, aug, sus, add…)
    // Extension = trailing digits + parens + accidentals
    const qualityMatch = rest.match(/^(m(?:aj)?|dim|aug|sus[24]?|add)?/i);
    const quality = qualityMatch ? qualityMatch[0] : '';
    const ext = rest.slice(quality.length);
    return { root, accidental: acc, quality, ext, bass };
}

// Render a chord name as an SVG <text> with <tspan> children matching sbnFormatChord.
// root: bold, 1.1× size; quality: weight 400; ext: 0.75× size, dy shift for superscript; bass: 0.9× size.
function formatChordSvg(name, x, y) {
    const BASE   = 14;   // base font-size in px
    const FILL   = '#2c3e50';
    const FONT   = 'Crimson Text,Georgia,serif';

    const { root, accidental, quality, ext, bass } = parseChordName(name);

    let inner = '';
    // Root
    inner += `<tspan font-size="${(BASE * 1.1).toFixed(1)}" font-weight="700">${escapeXml(root)}</tspan>`;
    // Accidental
    if (accidental) {
        inner += `<tspan font-size="${(BASE * 0.95).toFixed(1)}" dy="-0.6" baseline-shift="0">${escapeXml(accidental)}</tspan><tspan dy="0.6"></tspan>`;
    }
    // Quality
    if (quality) {
        inner += `<tspan font-weight="400">${escapeXml(quality)}</tspan>`;
    }
    // Extension (superscript)
    if (ext) {
        inner += `<tspan font-size="${(BASE * 0.75).toFixed(1)}" font-weight="600" dy="-4">${escapeXml(ext)}</tspan><tspan dy="4"></tspan>`;
    }
    // Bass
    if (bass) {
        inner += `<tspan font-size="${(BASE * 0.9).toFixed(1)}" font-weight="400">/${escapeXml(bass)}</tspan>`;
    }

    return `<text x="${x}" y="${y}" font-family="${FONT}" font-size="${BASE}" font-weight="600" fill="${FILL}" text-anchor="middle">${inner}</text>`;
}

function escapeXml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── Entry point ───────────────────────────────────────────────────────────────

const fs   = require('fs');
const path = require('path');

function run(raw) {
    let input;
    try { input = JSON.parse(raw); }
    catch (e) { process.stderr.write('render-tab-v2.cjs: invalid JSON\n'); process.exit(1); }
    process.stdout.write(renderTabRow(input));
}

const arg = process.argv[2];
if (!arg || arg === '--stdin') {
    let raw = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', c => { raw += c; });
    process.stdin.on('end', () => run(raw));
} else {
    run(fs.readFileSync(path.resolve(arg), 'utf8'));
}
