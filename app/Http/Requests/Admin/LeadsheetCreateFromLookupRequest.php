<?php

namespace App\Http\Requests\Admin;

use App\Services\MidiTranscriptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadsheetCreateFromLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:255'],
            'artist_hint'   => ['nullable', 'string', 'max:255'],
            'preferred_key' => ['nullable', 'string', 'max:10'],
            'version'       => ['nullable', 'string', Rule::in(['real_book', 'original', 'most_common'])],
            'build_voicings'=> ['nullable', 'boolean'],
            'extension_mode'=> ['nullable', 'string', Rule::in(['basic', 'extended'])],
            'voicing_style' => ['nullable', 'string', Rule::in(['popular', 'shell', 'drop2', 'archetype'])],
            'rhythm_override' => ['nullable', 'string', 'max:50'],
            'mode'          => ['nullable', 'string', Rule::in(['quick', 'assistant', 'audio'])],
            'youtube_id'    => ['nullable', 'string'],
            'local_audio'   => ['nullable', 'file', 'mimes:mp3,wav,m4a,ogg,flac', 'max:102400'],
            'bass_snap'     => ['nullable', 'boolean'],
            'tab_position_style' => ['nullable', 'string', Rule::in(['fretted', 'open'])],
            // ── basic-pitch detection tuning (audio mode) ────────────────────
            'detection_preset'     => ['nullable', 'string', Rule::in(['balanced', 'sensitive', 'strict', 'custom'])],
            'onset_threshold'      => ['nullable', 'numeric', 'min:0.05', 'max:0.95'],
            'frame_threshold'      => ['nullable', 'numeric', 'min:0.05', 'max:0.95'],
            'minimum_note_length'  => ['nullable', 'numeric', 'min:10', 'max:500'],
            'restrict_guitar_range'=> ['nullable', 'boolean'],
            'separate_stem'        => ['nullable', 'boolean'],
            // Two-phase stem workflow: when the admin has already separated &
            // auditioned, the modal sends a session token + the chosen stems.
            // (Legacy — the modal no longer auditions; it sends stem_choice below.)
            'stem_session'         => ['nullable', 'string', 'max:64'],
            'stems'                => ['nullable', 'array'],
            'stems.*'              => ['string', Rule::in(MidiTranscriptionService::STEM_NAMES)],
            // Modal stem quick-pick: 'mix' (no separation) or a comma-separated
            // stem set (e.g. 'guitar', 'guitar,bass', 'bass'). Separation runs on
            // submit — fine per-stem audition lives in the editor, not here.
            'stem_choice'          => ['nullable', 'string', 'max:64'],
        ];
    }
}
