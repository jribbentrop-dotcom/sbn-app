import sys
import os
import json
import numpy as np
import librosa
from basic_pitch.inference import predict
from basic_pitch import ICASSP_2022_MODEL_PATH

def transcribe(audio_path, params=None):
    try:
        # Normalize Windows paths for librosa/soundfile
        import os
        audio_path = os.path.abspath(audio_path).replace('\\', '/')

        # basic-pitch detection knobs (passed from the import modal). Defaults
        # match basic-pitch's own defaults — tuned for clearly-articulated
        # input. Soft / legato / orchestral material under-detects badly at
        # these defaults, so the modal lets the user loosen them.
        params = params or {}
        onset_threshold      = float(params.get('onset_threshold', 0.5))
        frame_threshold      = float(params.get('frame_threshold', 0.3))
        minimum_note_length  = float(params.get('minimum_note_length', 127.7))
        minimum_frequency    = params.get('minimum_frequency', None)
        maximum_frequency    = params.get('maximum_frequency', None)
        if minimum_frequency is not None:
            minimum_frequency = float(minimum_frequency)
        if maximum_frequency is not None:
            maximum_frequency = float(maximum_frequency)
        
        # 1. Load Audio (Limit to first 90 seconds)
        y, sr = librosa.load(audio_path, sr=22050, duration=90)
        
        # Save a temporary clipped version for basic-pitch to read
        # this ensures it only processes the 90s we want.
        temp_clip_path = audio_path + ".clip.wav"
        import soundfile as sf
        sf.write(temp_clip_path, y, sr)
        
        duration = librosa.get_duration(y=y, sr=sr)

        # 2. Beat Tracking
        # librosa.beat.beat_track gives us the estimated tempo and the frame
        # indices of beats. It assumes a roughly steady tempo, so it produces a
        # dense quarter-note grid — which the PHP pipeline relies on (one
        # beat_times entry == one quarter note / 480 ticks).
        #
        # NOTE: beat_track drifts on rubato playing (e.g. solo jazz guitar).
        # PLP (librosa.beat.plp) follows local tempo better but emits far fewer,
        # unevenly-spaced beats — it is a *pulse curve*, not a quarter grid —
        # which breaks the "1 entry = 1 quarter" contract and drops notes.
        # Rubato is therefore corrected by the user via the tap-to-mark re-time
        # UI, not by swapping the automatic detector here.
        tempo, beat_frames = librosa.beat.beat_track(y=y, sr=sr)
        beat_times = librosa.frames_to_time(beat_frames, sr=sr)

        # Ensure we have a beat at the very beginning if it's missing
        if len(beat_times) > 0 and beat_times[0] > 0.5:
            # Prepend a beat at 0 if the first beat is late
            beat_times = np.insert(beat_times, 0, 0.0)
        elif len(beat_times) == 0:
            beat_times = np.array([0.0])

        # 3. MIDI Extraction (Basic Pitch)
        # Use the clipped file. Detection thresholds come from the import
        # modal — lowering onset_threshold / frame_threshold recovers soft or
        # legato attacks that the defaults miss (e.g. solo jazz guitar).
        model_output, midi_data, note_events = predict(
            temp_clip_path,
            ICASSP_2022_MODEL_PATH,
            onset_threshold=onset_threshold,
            frame_threshold=frame_threshold,
            minimum_note_length=minimum_note_length,
            minimum_frequency=minimum_frequency,
            maximum_frequency=maximum_frequency,
        )
        
        # Cleanup temp clip
        if os.path.exists(temp_clip_path):
            os.remove(temp_clip_path)

        # Minimum note duration to qualify for harmonic bucketing.
        # Eliminates transients, basic-pitch false-positives, and melody grace notes
        # that would otherwise generate spurious chord labels.
        MIN_NOTE_DURATION = 0.05  # seconds (50 ms)

        # 4. Bin MIDI notes into beats
        # We want to group notes by which beat interval they start in.
        beats_json = []
        for i in range(len(beat_times)):
            start_t = beat_times[i]
            end_t = beat_times[i+1] if i+1 < len(beat_times) else duration
            
            # Find notes that START within this beat and meet the minimum duration.
            # note_events is a list of (start_sec, end_sec, pitch, velocity, amplitude)
            current_beat_notes = []   # list of (pitch, duration) tuples
            for note in note_events:
                n_start, n_end, n_pitch, n_vel, n_amp = note
                note_dur = n_end - n_start
                if n_start >= start_t and n_start < end_t and note_dur >= MIN_NOTE_DURATION:
                    current_beat_notes.append((int(n_pitch), float(note_dur)))

            # Deduplicate by pitch (keep longest duration for each pitch class)
            pitch_dur_map = {}
            for pitch, dur in current_beat_notes:
                if pitch not in pitch_dur_map or dur > pitch_dur_map[pitch]:
                    pitch_dur_map[pitch] = dur

            beats_json.append({
                "start": float(start_t),
                "end": float(end_t),
                # Legacy key: unique pitches (backward compatible with existing PHP code)
                "notes": list(pitch_dur_map.keys()),
                # New key: per-pitch durations for PHP-side weighting
                "note_durations": pitch_dur_map
            })

        notes_json = []
        for note in note_events:
            n_start, n_end, n_pitch, n_vel, n_amp = note
            notes_json.append({
                "start": float(n_start),
                "end": float(n_end),
                "pitch": int(n_pitch),
                "velocity": int(n_vel)
            })

        # 5. Output Result
        # Handle tempo as a scalar if it's returned as an array (librosa 0.10+)
        tempo_val = tempo[0] if isinstance(tempo, (np.ndarray, list)) else tempo

        result = {
            "success": True,
            "tempo": float(tempo_val),
            "duration": float(duration),
            "beats": beats_json,
            "beat_times": [float(t) for t in beat_times],
            "notes": notes_json,
            # Echo the detection params actually used, so the cached
            # transcriptionRaw records how this transcription was produced.
            "detection_params": {
                "onset_threshold": onset_threshold,
                "frame_threshold": frame_threshold,
                "minimum_note_length": minimum_note_length,
                "minimum_frequency": minimum_frequency,
                "maximum_frequency": maximum_frequency,
            },
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
    
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "No audio path provided"}))
        sys.exit(1)

    # Optional 2nd arg: PATH to a JSON file holding detection params from the
    # import modal. A file (not an inline JSON arg) is used deliberately —
    # shells mangle the double quotes inside `{"k":v}` when it is passed as a
    # bare argument, so the params would silently arrive corrupt and json.loads
    # would fall back to defaults.
    params = None
    if len(sys.argv) >= 3 and sys.argv[2].strip():
        params_path = sys.argv[2].strip()
        try:
            # utf-8-sig tolerates a leading BOM (some editors / shells add one).
            with open(params_path, 'r', encoding='utf-8-sig') as fh:
                params = json.load(fh)
        except (ValueError, TypeError, OSError):
            params = None

    transcribe(sys.argv[1], params)
