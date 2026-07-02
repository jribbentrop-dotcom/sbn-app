import sys
import os
import json
import shutil


def separate(input_path, output_path, device='cuda'):
    try:
        # Normalize Windows paths for consistency with the rest of the pipeline.
        input_path = os.path.abspath(input_path).replace('\\', '/')
        output_path = os.path.abspath(output_path).replace('\\', '/')

        import torch
        if device == 'cuda' and not torch.cuda.is_available():
            device = 'cpu'

        # Demucs output tree goes in a scratch dir next to the input so cleanup
        # (by this script, and by the PHP caller as a belt-and-suspenders step)
        # is a single directory removal.
        demucs_out_dir = input_path + '.demucs_out'
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

        # demucs writes: <out_dir>/htdemucs_6s/<input-basename-without-ext>/guitar.wav
        input_basename = os.path.splitext(os.path.basename(input_path))[0]
        guitar_path = os.path.join(demucs_out_dir, 'htdemucs_6s', input_basename, 'guitar.wav')
        guitar_path = guitar_path.replace('\\', '/')

        if not os.path.exists(guitar_path):
            raise Exception(f"Demucs did not produce a guitar stem at expected path: {guitar_path}")

        # Copy the guitar stem to the requested output path, then clean up the
        # full demucs output tree (vocals/bass/drums/other/piano.wav etc).
        shutil.copyfile(guitar_path, output_path)
        shutil.rmtree(demucs_out_dir, ignore_errors=True)

        result = {
            "success": True,
            "stem_path": output_path,
        }
        print("JSON_START")
        print(json.dumps(result))

    except Exception as e:
        import traceback
        print("JSON_START")
        print(json.dumps({
            "success": False,
            "error": str(e),
            "traceback": traceback.format_exc()
        }))


if __name__ == "__main__":
    import warnings
    warnings.filterwarnings("ignore")

    if len(sys.argv) < 3:
        print("JSON_START")
        print(json.dumps({"success": False, "error": "Usage: separate_stem.py <input_wav> <output_wav> [--device cuda|cpu]"}))
        sys.exit(1)

    in_path = sys.argv[1]
    out_path = sys.argv[2]

    device = 'cuda'
    if '--device' in sys.argv:
        idx = sys.argv.index('--device')
        if idx + 1 < len(sys.argv):
            device = sys.argv[idx + 1].strip() or 'cuda'

    separate(in_path, out_path, device)
