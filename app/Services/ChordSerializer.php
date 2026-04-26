<?php

namespace App\Services;

use App\Models\ChordDiagram;

/**
 * Chord Serializer
 *
 * Serializes ChordDiagram models for frontend consumption.
 * Handles transposition via ChordShapeCalculator when a root override is provided.
 * This is the shared implementation used by ChordLibraryController and Top10Controller.
 */
class ChordSerializer
{
    public function __construct(
        private ChordShapeCalculator $shapeCalculator
    ) {}

    /**
     * Serialize a chord diagram for the frontend.
     *
     * @param ChordDiagram $chord The chord diagram to serialize
     * @param string|null $rootOverride Optional root note to transpose to (e.g., from ?root= query param)
     * @return array Serialized chord data
     */
    public function serialize(ChordDiagram $chord, ?string $rootOverride = null): array
    {
        // Use caller-supplied root (e.g. from ?root= param) when present;
        // otherwise fall back to stored root_note.
        // Build display name from root+quality+extensions — the raw DB name is
        // an admin label ("m7 Drop 2 (Root A)") not a musical name.
        $root = $rootOverride ?? ($chord->root_note ?? null);
        $displayName = $root
            ? ($root . $chord->quality . ($chord->extensions ?? ''))
            : $chord->name;

        // If a root override is supplied and differs from the stored root,
        // transpose the diagram to that root (matches the index page's
        // search-result voicing). Without this, the hero diagram on the
        // detail page falls back to the raw DB shape and "loses" its frets.
        $storedRoot = $chord->root_note ?? null;
        if ($rootOverride && $storedRoot && $rootOverride !== $storedRoot) {
            $t = $this->shapeCalculator->calculateFrets($chord, $rootOverride);
            $diagramData = $t['diagram_data'] ?? null;
            $startFret = $t['start_fret'] ?? ($chord->start_fret ?? 1);
            $intervalLabels = $t['interval_labels'] ?? ($chord->interval_labels ?? '');
            $notes = $t['notes'] ?? ($chord->notes ?? '');
        } else {
            $diagramData = json_decode($chord->diagram_data ?? '{}', true)
                ?: ['positions' => [], 'barres' => [], 'muted' => [], 'open' => []];
            $startFret = $chord->start_fret ?? 1;
            $intervalLabels = $chord->interval_labels ?? '';
            $notes = $chord->notes ?? '';
        }

        return [
            'id' => $chord->id,
            'slug' => $chord->slug,
            'name' => $displayName,
            'root_note' => $root ?? $chord->root_note,
            'quality' => $chord->quality,
            'quality_label' => $chord->quality_label,
            'extensions' => $chord->extensions,
            'voicing_category' => $chord->voicing_category,
            'category_label' => $chord->category_label,
            'root_string' => $chord->root_string,
            'root_string_label' => $chord->root_string_label,
            'inversion' => $chord->inversion ?? 'root',
            'inversion_label' => $chord->inversion_label,
            'bass_note' => $chord->bass_note,
            'shape_family' => $chord->shape_family,
            'start_fret' => $startFret,
            'diagram_data' => $diagramData,
            'interval_labels' => $intervalLabels,
            'notes' => $notes,
            'popularity' => $chord->popularity,
            'difficulty' => $chord->difficulty,
            'description' => $chord->description,
        ];
    }
}
