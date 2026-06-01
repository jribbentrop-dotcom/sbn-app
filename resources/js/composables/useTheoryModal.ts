import { ref } from 'vue';
import type { EduWidgetSlug } from '@/edu/widgets/registry';

const activeSlug = ref<EduWidgetSlug | null>(null);

export function useTheoryModal() {
    function open(slug: EduWidgetSlug) {
        activeSlug.value = slug;
    }
    function close() {
        activeSlug.value = null;
    }
    return { activeSlug, open, close };
}
