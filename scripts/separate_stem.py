import sys
import os
import json
import shutil

# The six stems htdemucs_6s produces. Order is stable; the PHP side and the
# import-modal checkboxes rely on these exact names.
STEM_NAMES = ["guitar", "bass", "vocals", "drums", "piano", "other"]


def _emit(payload):
    """Print the JSON_START handshake + one line of JSON on real stdout."""
    print("JSON_START")
    print(json.dumps(payload))


def _run_demucs(input_path, demucs_out_dir, device):
    """Run htdemucs_6s once; returns the dir holding the six <stem>.wav files."""
    import torch
    if device == 'cuda' and not torch.cuda.is_available():
        device = 'cpu'

    if os.path.isdir(demucs_out_dir):
        shutil.rmtree(demucs_out_dir)
    os.makedirs(demucs_out_dir, exist_ok=True)

    import demucs.separate
    # NOTE: Demucs prints a per-chunk tqdm progress bar to stderr. When this
    # script is launched via PHP proc_open with a stderr *pipe*, that flood
    # fills the OS pipe buffer and deadlocks — the child blocks writing to
    # stderr while PHP is still blocked reading stdout. So we redirect BOTH
    # Python-level stdout and stderr to os.devnull for the duration of the
    # separation call (our own JSON_START result is printed afterwards, on
    # the restored real stdout). The PHP side also sends the child's stderr
    # to a temp file rather than a pipe, as belt-and-suspenders.
    import contextlib
    with open(os.devnull, 'w') as devnull:
        with contextlib.redirect_stdout(devnull), contextlib.redirect_stderr(devnull):
            demucs.separate.main([
                '-n', 'htdemucs_6s',
                '--device', device,
                '-o', demucs_out_dir,
                input_path,
            ])

    # demucs writes: <out_dir>/htdemucs_6s/<input-basename-without-ext>/<stem>.wav
    input_basename = os.path.splitext(os.path.basename(input_path))[0]
    return os.path.join(demucs_out_dir, 'htdemucs_6s', input_basename)


def separate_all(input_path, output_dir, device='cuda'):
    """
    Mode 1 (audition): run Demucs and copy ALL six stems into output_dir,
    keeping them for playback + a later summed transcription. Returns the map
    of stem name -> persisted path.
    """
    try:
        input_path = os.path.abspath(input_path).replace('\\', '/')
        output_dir = os.path.abspath(output_dir).replace('\\', '/')
        os.makedirs(output_dir, exist_ok=True)

        demucs_out_dir = input_path + '.demucs_out'
        stem_src_dir = _run_demucs(input_path, demucs_out_dir, device)

        stems = {}
        for name in STEM_NAMES:
            src = os.path.join(stem_src_dir, f'{name}.wav').replace('\\', '/')
            if not os.path.exists(src):
                # htdemucs_6s should always emit all six; skip a missing one
                # rather than failing the whole separation.
                continue
            dst = os.path.join(output_dir, f'{name}.wav').replace('\\', '/')
            shutil.copyfile(src, dst)
            stems[name] = dst

        shutil.rmtree(demucs_out_dir, ignore_errors=True)

        if not stems:
            raise Exception("Demucs produced no stems in " + stem_src_dir)

        _emit({"success": True, "stems": stems})
    except Exception as e:
        import traceback
        _emit({"success": False, "error": str(e), "traceback": traceback.format_exc()})


def sum_stems(stems_dir, stem_list, output_path):
    """
    Mode 2 (transcribe): mix the chosen already-separated stems from stems_dir
    into one WAV at output_path. No Demucs re-run — reuses the persisted stems.
    Peak-normalises the sum to -1 dBFS so adding stems can't clip.
    """
    try:
        import numpy as np
        import soundfile as sf

        stems_dir = os.path.abspath(stems_dir).replace('\\', '/')
        output_path = os.path.abspath(output_path).replace('\\', '/')

        wanted = [s.strip() for s in stem_list if s.strip() in STEM_NAMES]
        if not wanted:
            raise Exception("No valid stems requested: " + repr(stem_list))

        mix = None
        sr = None
        used = []
        for name in wanted:
            path = os.path.join(stems_dir, f'{name}.wav').replace('\\', '/')
            if not os.path.exists(path):
                continue
            data, this_sr = sf.read(path, always_2d=True)  # (frames, channels)
            if mix is None:
                mix = data.astype('float64')
                sr = this_sr
            else:
                # Guard against length/channel mismatches between stems.
                if this_sr != sr:
                    raise Exception(f"Sample-rate mismatch: {name} is {this_sr}, expected {sr}")
                if data.shape[1] != mix.shape[1]:
                    # Collapse to mono on either side if channel counts differ.
                    data = data.mean(axis=1, keepdims=True)
                    if mix.shape[1] != 1:
                        mix = mix.mean(axis=1, keepdims=True)
                n = min(len(mix), len(data))
                mix = mix[:n] + data[:n]
            used.append(name)

        if mix is None:
            raise Exception("None of the requested stems exist in " + stems_dir)

        # Peak-normalise to -1 dBFS (~0.891) to prevent clipping from the sum.
        peak = float(np.max(np.abs(mix))) if mix.size else 0.0
        if peak > 0:
            mix = mix * (0.891 / peak)

        sf.write(output_path, mix, sr)
        _emit({"success": True, "stem_path": output_path, "stems_used": used})
    except Exception as e:
        import traceback
        _emit({"success": False, "error": str(e), "traceback": traceback.format_exc()})


def separate_single(input_path, output_path, device='cuda'):
    """
    Legacy mode: run Demucs and copy ONLY guitar.wav to output_path. Retained
    for back-compat with any caller still on the single-stem contract.
    """
    try:
        input_path = os.path.abspath(input_path).replace('\\', '/')
        output_path = os.path.abspath(output_path).replace('\\', '/')

        demucs_out_dir = input_path + '.demucs_out'
        stem_src_dir = _run_demucs(input_path, demucs_out_dir, device)

        guitar_path = os.path.join(stem_src_dir, 'guitar.wav').replace('\\', '/')
        if not os.path.exists(guitar_path):
            raise Exception(f"Demucs did not produce a guitar stem at: {guitar_path}")

        shutil.copyfile(guitar_path, output_path)
        shutil.rmtree(demucs_out_dir, ignore_errors=True)
        _emit({"success": True, "stem_path": output_path})
    except Exception as e:
        import traceback
        _emit({"success": False, "error": str(e), "traceback": traceback.format_exc()})


def _get_flag(name, default=None):
    if name in sys.argv:
        idx = sys.argv.index(name)
        if idx + 1 < len(sys.argv):
            return sys.argv[idx + 1].strip() or default
    return default


if __name__ == "__main__":
    import warnings
    warnings.filterwarnings("ignore")

    device = _get_flag('--device', 'cuda') or 'cuda'

    # Mode dispatch:
    #   --all-stems <input.wav> <output_dir>            → separate_all
    #   --sum <a,b,c> --stems-dir <dir> <output.wav>    → sum_stems
    #   <input.wav> <output.wav> [--device ...]         → separate_single (legacy)
    if '--all-stems' in sys.argv:
        # positional after the flag: input, output_dir
        idx = sys.argv.index('--all-stems')
        try:
            in_path = sys.argv[idx + 1]
            out_dir = sys.argv[idx + 2]
        except IndexError:
            _emit({"success": False, "error": "Usage: separate_stem.py --all-stems <input.wav> <output_dir> [--device cuda|cpu]"})
            sys.exit(1)
        separate_all(in_path, out_dir, device)

    elif '--sum' in sys.argv:
        stem_csv = _get_flag('--sum', '')
        stems_dir = _get_flag('--stems-dir')
        # output path is the first bare arg that isn't a flag/flag-value
        flags = {'--sum', '--stems-dir', '--device'}
        consumed = set()
        for f in flags:
            if f in sys.argv:
                i = sys.argv.index(f)
                consumed.add(i)
                consumed.add(i + 1)
        out_path = None
        for i, a in enumerate(sys.argv[1:], start=1):
            if i in consumed:
                continue
            out_path = a
            break
        if not stems_dir or not out_path:
            _emit({"success": False, "error": "Usage: separate_stem.py --sum <a,b,c> --stems-dir <dir> <output.wav>"})
            sys.exit(1)
        sum_stems(stems_dir, (stem_csv or '').split(','), out_path)

    else:
        if len(sys.argv) < 3:
            _emit({"success": False, "error": "Usage: separate_stem.py <input_wav> <output_wav> [--device cuda|cpu]"})
            sys.exit(1)
        separate_single(sys.argv[1], sys.argv[2], device)
