<script setup lang="ts">
import { watchEffect, ref } from 'vue';

export interface ChordDiagramData {
    id: number;
    slug: string;
    name: string;
    root_note: string;
    quality: string;
    quality_label: string;
    extensions?: string | null;
    voicing_category: string;
    category_label: string;
    root_string: string;
    root_string_label: string;
    inversion: string;
    inversion_label: string;
    bass_note?: string | null;
    shape_family?: string | null;
    start_fret: number;
    diagram_data: {
        positions: Array<{ string: number; fret: number; finger?: number | string }>;
        barres: Array<{ fret: number; from: number; to: number; finger?: number | string }>;
        muted: number[];
        open: number[];
    };
    interval_labels?: string | null;
    notes?: string | null;
    popularity?: number | null;
    difficulty?: number | null;
    description?: string | null;
}

interface Props {
    chord: ChordDiagramData;
    /** Fill color for chord dots. Defaults to var(--clr-red). */
    dotColor?: string;
    /** When true, colorize dots by interval function using chord.interval_labels. */
    showGuideTones?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    dotColor: 'var(--clr-red)',
    showGuideTones: true,
});
const svgHtml = ref('');

function diagramDataToFretString(data: ChordDiagramData['diagram_data']): string {
    const frets: (number | 'x')[] = ['x', 'x', 'x', 'x', 'x', 'x'];
    for (const s of data.open ?? []) {
        if (s >= 1 && s <= 6) frets[s - 1] = 0;
    }
    for (const pos of data.positions ?? []) {
        if (pos.string >= 1 && pos.string <= 6) frets[pos.string - 1] = pos.fret;
    }
    for (const s of data.muted ?? []) {
        if (s >= 1 && s <= 6) frets[s - 1] = 'x';
    }
    return frets.map(f => f === 'x' ? 'x' : (f as number).toString(16)).join('');
}

function diagramDataToFingerString(data: ChordDiagramData['diagram_data']): string {
    if (!data) return '000000';
    const fingers: string[] = ['0', '0', '0', '0', '0', '0'];
    
    // Check positions for fingers
    const positions = data.positions || [];
    for (const pos of positions) {
        if (pos.string >= 1 && pos.string <= 6 && pos.finger && pos.finger !== '0') {
            fingers[pos.string - 1] = String(pos.finger);
        }
    }
    
    // Check barres for fingers (barred strings might be in positions too, but let's be safe)
    const barres = data.barres || [];
    for (const barre of barres) {
        if (barre.finger && barre.finger !== '0') {
            const from = Math.min(barre.from, barre.to);
            const to = Math.max(barre.from, barre.to);
            for (let s = from; s <= to; s++) {
                if (s >= 1 && s <= 6) {
                    fingers[s - 1] = String(barre.finger);
                }
            }
        }
    }
    
    return fingers.join('');
}

watchEffect(() => {
    if (typeof (window as any).sbnRenderDiagramSVG === 'function') {
        const data = props.chord.diagram_data;
        const posFrets = (data.positions ?? []).map(p => p.fret);
        const barreFrets = (data.barres ?? []).map(b => b.fret);
        const maxFret = Math.max(0, ...posFrets, ...barreFrets);
        const hasOpen = (data.open ?? []).length > 0 || posFrets.some(f => f === 0);
        // Nut only when all frets ≤ 4 AND there are open strings (movable shapes at low frets get a position marker)
        const displayPosition = (maxFret > 0 && maxFret <= 4 && hasOpen) ? 1 : (props.chord.start_fret ?? 1);
        const voicing = {
            frets:       diagramDataToFretString(data),
            fret_string: diagramDataToFretString(data),
            position:    displayPosition,
            start_fret:  displayPosition,
            fingers:     diagramDataToFingerString(data),
        };
        
        const gtLabels = props.showGuideTones ? (props.chord.interval_labels ?? null) : null;
        svgHtml.value = (window as any).sbnRenderDiagramSVG(voicing, {
            showFingers: true,
            dotColor: props.dotColor,
            intervalLabels: gtLabels || undefined,
        });
    }
});
</script>

<template>
    <div class="chord-diagram-svg" v-html="svgHtml || ''" />
</template>

<style scoped>
.chord-diagram-svg :deep(svg) {
    width: 100%;
    height: auto;
    display: block;
}
</style>
