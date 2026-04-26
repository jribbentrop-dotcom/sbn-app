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
        positions: Array<{ string: number; fret: number }>;
        barres: Array<{ fret: number; from: number; to: number }>;
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
}

const props = defineProps<Props>();
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
    const fingers: string[] = ['0', '0', '0', '0', '0', '0'];
    for (const pos of data.positions ?? []) {
        if (pos.string >= 1 && pos.string <= 6 && (pos as any).finger) {
            fingers[pos.string - 1] = String((pos as any).finger);
        }
    }
    return fingers.join('');
}

watchEffect(() => {
    if (typeof (window as any).sbnRenderDiagramSVG === 'function') {
        svgHtml.value = (window as any).sbnRenderDiagramSVG({
            frets:    diagramDataToFretString(props.chord.diagram_data),
            position: props.chord.start_fret ?? 1,
            fingers:  diagramDataToFingerString(props.chord.diagram_data),
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
