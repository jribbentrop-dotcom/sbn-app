<?php

namespace App\Http\Requests\Admin;

use App\Models\Fretboard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FretboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'root_note'        => ['nullable', 'string', Rule::in(['C', 'C#', 'Db', 'D', 'D#', 'Eb', 'E', 'F', 'F#', 'Gb', 'G', 'G#', 'Ab', 'A', 'A#', 'Bb', 'B'])],
            'slug'             => ['nullable', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'display_mode'     => ['required', Rule::in(['chord', 'scale', 'sequence', 'positions'])],
            'fret_count'       => ['required', 'integer', 'min:4', 'max:24'],
            'start_fret'       => ['required', 'integer', 'min:1', 'max:20'],
            'show_guide_tones' => ['nullable', 'boolean'],
            'show_rh_fingers'  => ['nullable', 'boolean'],
            'voicings'         => ['nullable', 'string'], // JSON string from hidden field
            'windows'          => ['nullable', 'string'], // JSON string from hidden field (positions mode)
            'start_window'     => ['nullable', 'integer', 'min:0', 'max:255'],
        ];
    }

    /** Validated + shaped data, ready for Fretboard::create/update. */
    public function payload(): array
    {
        $raw = $this->validated();

        // Checkboxes arrive as '1' or absent; cast to bool
        $raw['show_guide_tones'] = (bool) ($raw['show_guide_tones'] ?? false);
        $raw['show_rh_fingers']  = (bool) ($raw['show_rh_fingers']  ?? false);

        // Decode voicings JSON → array
        $raw['voicings'] = $raw['voicings']
            ? json_decode($raw['voicings'], true) ?? []
            : [];

        // Decode windows JSON → array (positions mode; null when unused)
        $raw['windows'] = ($raw['windows'] ?? null)
            ? json_decode($raw['windows'], true) ?? []
            : [];

        // Clamp start_window to a valid index into windows[] (0 when out of range)
        $windowCount = count($raw['windows']);
        $startWindow = (int) ($raw['start_window'] ?? 0);
        $raw['start_window'] = ($windowCount > 0 && $startWindow >= 0 && $startWindow < $windowCount)
            ? $startWindow
            : 0;

        // Default slug from title if blank
        if (empty($raw['slug'])) {
            $raw['slug'] = Str::slug($raw['title']);
        }

        $raw['slug'] = $this->uniqueSlug($raw['slug'], $this->route('fretboard')?->id);

        return $raw;
    }

    private function uniqueSlug(string $slug, ?int $exceptId): string
    {
        $base = Str::slug($slug) ?: 'fretboard';
        $candidate = $base;
        $i = 2;
        while (
            Fretboard::where('slug', $candidate)
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->exists()
        ) {
            $candidate = $base . '-' . $i++;
        }
        return $candidate;
    }
}
