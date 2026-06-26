#!/usr/bin/env python3
"""
Batch-export MuseScore files (.mscz / .mscx) to MusicXML via the MuseScore CLI.

Why this exists: importing old MuseScore lead sheets into the SBN tab editor used
to require opening each score in MuseScore and manually exporting it to MusicXML.
MuseScore 4 ships a headless batch-convert mode, so that step can be a one-liner.

The companion automation — collapsing a 2-voice score (bass + melody) into the
single voice the tab editor expects — now happens automatically *inside* the
editor's MusicXML import (see `flattenVoices()` in
resources/views/admin/leadsheets/edit.blade.php). So the produced .musicxml is
import-ready as-is; you do NOT need to merge voices in MuseScore first.

Usage:
    python3 scripts/export_mscz.py <file-or-folder> [-o OUTDIR]

    # single file (writes alongside the source by default)
    python3 scripts/export_mscz.py "docs/AKKORDE - TOP10 Bossa Nova.mscz"

    # a whole folder, output into docs/
    python3 scripts/export_mscz.py docs/scores -o docs

Notes:
  - Finds MuseScore4.exe in the usual install locations; override with $MSCORE.
  - Uses MuseScore's JSON batch job so all files convert in one process launch
    (much faster than one launch per file).
  - .uncompressed .mscx and compressed .mscz are both accepted.
"""
import argparse
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

MSCORE_CANDIDATES = [
    os.environ.get("MSCORE"),
    r"C:\Program Files\MuseScore 4\bin\MuseScore4.exe",
    r"C:\Program Files (x86)\MuseScore 4\bin\MuseScore4.exe",
    r"C:\Program Files\MuseScore 3\bin\MuseScore3.exe",
    "musescore4", "mscore4", "musescore", "mscore",  # PATH fallbacks (Linux/mac)
]


def find_mscore() -> str:
    for cand in MSCORE_CANDIDATES:
        if not cand:
            continue
        if os.path.sep in cand or "/" in cand:
            if Path(cand).is_file():
                return cand
        else:
            # bare command name — trust PATH
            from shutil import which
            found = which(cand)
            if found:
                return found
    sys.exit(
        "MuseScore CLI not found. Install MuseScore 4, or set the MSCORE env var "
        "to the full path of MuseScore4.exe."
    )


def collect_sources(target: Path) -> list[Path]:
    if target.is_file():
        return [target]
    if target.is_dir():
        files = sorted(
            p for p in target.iterdir()
            if p.suffix.lower() in (".mscz", ".mscx")
        )
        if not files:
            sys.exit(f"No .mscz/.mscx files found in {target}")
        return files
    sys.exit(f"Not found: {target}")


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("target", help="A .mscz/.mscx file, or a folder of them.")
    ap.add_argument("-o", "--outdir", default=None,
                    help="Output directory for .musicxml files "
                         "(default: alongside each source).")
    args = ap.parse_args()

    mscore = find_mscore()
    sources = collect_sources(Path(args.target))
    outdir = Path(args.outdir) if args.outdir else None
    if outdir:
        outdir.mkdir(parents=True, exist_ok=True)

    # Build a MuseScore batch job: a list of {in, out} pairs.
    jobs = []
    out_paths = []
    for src in sources:
        dest_dir = outdir if outdir else src.parent
        dest = dest_dir / (src.stem + ".musicxml")
        jobs.append({"in": str(src.resolve()), "out": str(dest.resolve())})
        out_paths.append(dest)

    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False,
                                     encoding="utf-8") as jf:
        json.dump(jobs, jf)
        job_path = jf.name

    print(f"MuseScore: {mscore}")
    print(f"Converting {len(jobs)} file(s)...")
    try:
        proc = subprocess.run(
            [mscore, "-j", job_path],
            capture_output=True, text=True,
        )
    finally:
        os.unlink(job_path)

    if proc.returncode != 0:
        sys.stderr.write(proc.stdout)
        sys.stderr.write(proc.stderr)
        sys.exit(f"MuseScore exited with code {proc.returncode}")

    ok = 0
    for src, dest in zip(sources, out_paths):
        if dest.is_file() and dest.stat().st_size > 0:
            print(f"  OK  {src.name}  ->  {dest}")
            ok += 1
        else:
            print(f"  FAIL {src.name}  (no output written)")
    print(f"Done: {ok}/{len(jobs)} converted.")
    if ok != len(jobs):
        sys.exit(1)


if __name__ == "__main__":
    main()
