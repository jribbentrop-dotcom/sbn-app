#!/usr/bin/env python3
"""
Extract a clean, per-measure chord/harmony transcript from a MusicXML file.

Why this exists: turning a MusicXML reference score (theory document, chord-
progression chart, lead sheet, etc.) into SBN course content requires reading
every <harmony> tag in the file. Two things make this harder than it looks:

1. Chord symbols are not reliably on one part/voice. A 4-part score (piano +
   3x guitar, or melody + chords + tab) may put the <harmony> tags for measure
   12 on a totally different part than measure 13. You must merge across all
   parts per measure, not just read part 1.

2. MuseScore exports a "Roman numeral analysis" layer and a "chord symbol"
   layer as separate <harmony> tags for the SAME beat, interleaved with the
   real chords in document order. They are not always positionally paired
   1-to-1 in a way you can trust blindly — verify suspicious cases (e.g. a
   `kind="none"` harmony is usually a "hold the previous chord, don't reprint
   the symbol" marker, not a real chord) against the actual chord letters.

This script does the merging/extraction mechanically so you don't have to
re-derive it ad hoc each time. It does NOT do music-theory interpretation
(Roman numeral labeling, key detection, deciding what a progression "is
called") — that part still needs a human/Claude pass, because the same four
chords can mean different things depending on context (see CLAUDE.md notes
on the Pachelbel/Andalusian examples using a "local key of convenience").

Usage:
    python3 extract_musicxml_harmony.py "/path/to/file.musicxml"
    python3 extract_musicxml_harmony.py "/path/to/file.musicxml" --measures 1-58
"""
import argparse
import re
import sys


def get_parts(content):
    return re.findall(r'<part id="([^"]+)">(.*?)</part>', content, re.S)


def parse_harmony(body):
    chords = []
    for h in re.findall(r'<harmony.*?</harmony>', body, re.S):
        root = re.search(r'<root-step[^>]*>(.*?)</root-step>', h)
        alter = re.search(r'<root-alter>(.*?)</root-alter>', h)
        numeral = re.search(r'<numeral-root[^>]*>(.*?)</numeral-root>', h)
        kind = re.search(r'<kind[^>]*>(.*?)</kind>', h)
        kindtext = re.search(r'<kind text="([^"]*)"', h)
        bass = re.search(r'<bass-step>(.*?)</bass-step>', h)
        bassalter = re.search(r'<bass-alter>(.*?)</bass-alter>', h)
        degrees = re.findall(r'<degree-value>(.*?)</degree-value>', h)

        if root:
            sign = '#' if alter and alter.group(1) == '1' else 'b' if alter and alter.group(1) == '-1' else ''
            label = f"{root.group(1)}{sign}"
        elif numeral:
            label = f"numeral:{numeral.group(1)}"
        else:
            label = '?'  # usually a kind="none" hold-marker — check raw XML if this matters

        k = kindtext.group(1) if kindtext else (kind.group(1) if kind else '')
        b = ''
        if bass:
            bsign = '#' if bassalter and bassalter.group(1) == '1' else 'b' if bassalter and bassalter.group(1) == '-1' else ''
            b = f"/{bass.group(1)}{bsign}"
        deg = f"[{','.join(degrees)}]" if degrees else ''
        chords.append(f"{label}{k}{deg}{b}")
    return chords


def extract(content, measure_filter=None):
    """Merge harmony + section-label text across ALL parts, per measure number."""
    all_data = {}
    for pid, p in get_parts(content):
        measures = re.findall(r'<measure number="(\d+)"[^>]*>(.*?)</measure>', p, re.S)
        for num, body in measures:
            n = int(num)
            if measure_filter and n not in measure_filter:
                continue
            words = re.findall(r'<words[^>]*>(.*?)</words>', body)
            chords = parse_harmony(body)
            fifths = re.search(r'<fifths>(-?\d+)</fifths>', body)

            d = all_data.setdefault(n, {'words': [], 'chords': [], 'fifths': None})
            if words:
                d['words'].extend(words)
            if chords and not d['chords']:
                # first part with chords for this measure wins — don't overwrite
                d['chords'] = chords
            if fifths:
                d['fifths'] = fifths.group(1)
    return all_data


def parse_measure_range(spec):
    if not spec:
        return None
    a, b = spec.split('-')
    return set(range(int(a), int(b) + 1))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('file')
    ap.add_argument('--measures', help='e.g. 1-58')
    args = ap.parse_args()

    with open(args.file, encoding='utf-8') as f:
        content = f.read()

    data = extract(content, parse_measure_range(args.measures))

    for n in sorted(data):
        d = data[n]
        if d['words']:
            joined = ' '.join(w.replace('&quot;', '"') for w in d['words'])
            suffix = f" [fifths={d['fifths']}]" if d['fifths'] else ''
            print(f"--- m{n}: {joined}{suffix}")
        if d['chords']:
            print(f"  m{n}: {d['chords']}")


if __name__ == '__main__':
    main()
