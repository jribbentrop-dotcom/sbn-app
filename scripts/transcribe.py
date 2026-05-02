import sys
import os
import json
import numpy as np
import librosa
from basic_pitch.inference import predict
from basic_pitch import ICASSP_2022_MODEL_PATH

def transcribe(audio_path):
    try:
        # Normalize Windows paths for librosa/soundfile
        import os
        audio_path = os.path.abspath(audio_path).replace('\\', '/')
        
        # 1. Load Audio (Limit to first 90 seconds)
        y, sr = librosa.load(audio_path, sr=22050, duration=90)
        
        # Save a temporary clipped version for basic-pitch to read
        # this ensures it only processes the 90s we want.
        temp_clip_path = audio_path + ".clip.wav"
        import soundfile as sf
        sf.write(temp_clip_path, y, sr)
        
        duration = librosa.get_duration(y=y, sr=sr)

        # 2. Beat Tracking (The "Powerful" part)
        # librosa.beat.beat_track gives us the estimated tempo and the frame indices of beats
        tempo, beat_frames = librosa.beat.beat_track(y=y, sr=sr)
        beat_times = librosa.frames_to_time(beat_frames, sr=sr)
        
        # Ensure we have a beat at the very beginning if it's missing
        if len(beat_times) > 0 and beat_times[0] > 0.5:
            # Prepend a beat at 0 if the first beat is late
            beat_times = np.insert(beat_times, 0, 0.0)
        elif len(beat_times) == 0:
            beat_times = np.array([0.0])

        # 3. MIDI Extraction (Basic Pitch)
        # Use the clipped file
        model_output, midi_data, note_events = predict(temp_clip_path, ICASSP_2022_MODEL_PATH)
        
        # Cleanup temp clip
        if os.path.exists(temp_clip_path):
            os.remove(temp_clip_path)

        # 4. Bin MIDI notes into beats
        # We want to group notes by which beat interval they start in.
        beats_json = []
        for i in range(len(beat_times)):
            start_t = beat_times[i]
            end_t = beat_times[i+1] if i+1 < len(beat_times) else duration
            
            # Find notes that start within this beat
            # note_events is a list of (start_sec, end_sec, pitch, velocity, amplitude)
            current_beat_notes = []
            for note in note_events:
                n_start, n_end, n_pitch, n_vel, n_amp = note
                if n_start >= start_t and n_start < end_t:
                    current_beat_notes.append(int(n_pitch))
            
            beats_json.append({
                "start": float(start_t),
                "end": float(end_t),
                "notes": list(set(current_beat_notes)) # Unique pitches in this beat
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
            "notes": notes_json
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
    
    transcribe(sys.argv[1])
