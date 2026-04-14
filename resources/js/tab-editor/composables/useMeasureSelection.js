import { ref, computed } from 'vue';

export function useMeasureSelection() {
    const selection = ref(new Set());
    const selectionAnchor = ref(null);

    const hasSelection = computed(() => selection.value.size > 0);
    const selectionCount = computed(() => selection.value.size);

    function isSelected(index) {
        return selection.value.has(index);
    }

    function selectSingle(index) {
        selection.value.clear();
        selection.value.add(index);
        selectionAnchor.value = index;
    }

    function toggleSelect(index) {
        if (selection.value.has(index)) {
            selection.value.delete(index);
            if (selectionAnchor.value === index) {
                selectionAnchor.value = null;
            }
        } else {
            selection.value.add(index);
            selectionAnchor.value = index;
        }
    }

    function selectRange(index) {
        if (selectionAnchor.value === null) {
            selectSingle(index);
            return;
        }
        
        selection.value.clear();
        const start = Math.min(selectionAnchor.value, index);
        const end = Math.max(selectionAnchor.value, index);
        
        for (let i = start; i <= end; i++) {
            selection.value.add(i);
        }
    }

    function clearSelection() {
        selection.value.clear();
        selectionAnchor.value = null;
    }

    function getSelectedIndices() {
        return Array.from(selection.value).sort((a, b) => a - b);
    }

    return {
        selectionAnchor,
        hasSelection,
        selectionCount,
        isSelected,
        selectSingle,
        toggleSelect,
        selectRange,
        clearSelection,
        getSelectedIndices
    };
}
