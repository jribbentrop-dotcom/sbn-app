<?php

namespace App\Services;

use App\Models\ChordDiagram;
use App\Services\HarmonicContext;

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

        // Always run through the calculator. Stored diagram_data/start_fret can
        // be inconsistent with the row's root_note label (legacy "store at any
        // low position" rows + auto-defaulted root_note='C' in the editor).
        // The calculator is root-agnostic — it derives positions from the bass
        // interval — so transposing to the row's own root self-heals the label
        // mismatch. The other public pages already go through this path.
        $effectiveRoot = $root ?? 'C';
        $t = $this->shapeCalculator->calculateFrets($chord, $effectiveRoot);
        return $this->buildSerializedArray($chord, $t, $root, $displayName);
    }

    /**
     * Serialize a chord diagram but override the quality and inversion metadata.
     * Used when a shape is stored under one quality but represents another (alias inversions).
     */
    public function serializeAs(ChordDiagram $chord, string $root, string $quality, string $inversion, string $inversionLabel): array
    {
        $extensions = '';
        $displayName = $root . $quality;

        // The calculator uses $shape->quality and $shape->inversion to determine
        // the bass interval offset. For alias inversions the stored values are wrong
        // for the target interpretation, so we proxy with a plain object override.
        $proxy = (object) array_merge((array) $chord->getAttributes(), [
            'quality'   => $quality,
            'inversion' => $inversion,
        ]);
        $t = $this->shapeCalculator->calculateFrets($proxy, $root);
        $diagramData = $t['diagram_data'] ?? null;
        $startFret = $t['start_fret'] ?? ($chord->start_fret ?? 1);
        $intervalLabels = $t['interval_labels'] ?? ($chord->interval_labels ?? '');
        $notes = $t['notes'] ?? ($chord->notes ?? '');

        if (empty($diagramData) || (empty($diagramData['positions']) && empty($diagramData['open']))) {
            $diagramData = json_decode($chord->diagram_data ?? '{}', true)
                ?: ['positions' => [], 'barres' => [], 'muted' => [], 'open' => []];
            $startFret = $chord->start_fret ?? 1;
        }

        return [
            'id' => $chord->id,
            'slug' => $chord->slug,
            'name' => $displayName,
            'root_note' => $root,
            'quality' => $quality,
            'quality_label' => $chord->quality_label,
            'extensions' => $extensions,
            'voicing_category' => $chord->voicing_category,
            'category_label' => $chord->category_label,
            'root_string' => $chord->root_string,
            'root_string_label' => $chord->root_string_label,
            'inversion' => $inversion,
            'inversion_label' => $inversionLabel,
            'bass_note' => $this->spellBassNote(null, $root, $quality, $inversion),
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

    /**
     * Serialize a chord diagram with an explicit bass note (true slash chord).
     */
    public function serializeWithBass(ChordDiagram $chord, string $root, string $bass): array
    {
        $displayName = $root . $chord->quality . ($chord->extensions ?? '') . '/' . $bass;
        $t = $this->shapeCalculator->calculateFretsWithBass($chord, $root, $bass);
        return $this->buildSerializedArray($chord, $t, $root, $displayName);
    }

    /**
     * Spell a bass note correctly for the given root context.
     * For stored slash-chord bass notes (true slash): re-spell to the enharmonic
     * equivalent that matches the root's flat/sharp family.
     * For inversion bass notes (null stored): derive from quality + inversion.
     */
    private function spellBassNote(?string $storedBass, string $root, string $quality, string $inversion): ?string
    {
        static $semi  = [
            'C'=>0,'B#'=>0,'C#'=>1,'Db'=>1,'D'=>2,'D#'=>3,'Eb'=>3,
            'E'=>4,'F'=>5,'F#'=>6,'Gb'=>6,'G'=>7,'G#'=>8,'Ab'=>8,
            'A'=>9,'A#'=>10,'Bb'=>10,'B'=>11,
        ];
        static $sharp = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
        static $flat  = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];
        // Qualities whose inversion tones are always spelled with flats (b3, b5, b7).
        static $flatQuals = ['m7','m7b5','min','o7','mMaj7','m6','dom7','maj6'];

        $useFlats = HarmonicContext::spellingUsesFlats($root)
            || in_array($quality, $flatQuals, true);

        if ($storedBass !== null && $storedBass !== '') {
            if (!isset($semi[$storedBass])) return $storedBass;
            $s = $semi[$storedBass];
            return $useFlats ? $flat[$s] : $sharp[$s];
        }

        $derived = ChordShapeCalculator::deriveBassNote($root, $quality, $inversion);
        if ($derived === null || !isset($semi[$derived])) return $derived;
        return $useFlats ? $flat[$semi[$derived]] : $sharp[$semi[$derived]];
    }

    /**
     * Internal helper to build the serialized array from calculator output.
     */
    private function buildSerializedArray(ChordDiagram $chord, array $t, ?string $root, string $displayName): array
    {
        $diagramData = $t['diagram_data'] ?? null;
        $startFret = $t['start_fret'] ?? ($chord->start_fret ?? 1);
        $intervalLabels = $t['interval_labels'] ?? ($chord->interval_labels ?? '');
        $notes = $t['notes'] ?? ($chord->notes ?? '');

        if (empty($diagramData) || (empty($diagramData['positions']) && empty($diagramData['open']))) {
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
            'bass_note' => $this->spellBassNote(
                $chord->bass_note,
                $root ?? $chord->root_note ?? 'C',
                $chord->quality ?? '',
                $chord->inversion ?? 'root'
            ),
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
