<script setup lang="ts">
import { defineAsyncComponent, onUnmounted, onMounted } from 'vue';
import { useTheoryModal } from '@/composables/useTheoryModal';
import { eduWidgets } from '@/edu/widgets/registry';
import { widgetCatalog } from '@/edu/widgets/catalog';

const { activeSlug, close } = useTheoryModal();

function resolveComponent() {
    if (!activeSlug.value) return null;
    const thunk = eduWidgets[activeSlug.value];
    if (!thunk) return null;
    return defineAsyncComponent(thunk);
}

function activeTitle() {
    if (!activeSlug.value) return '';
    return widgetCatalog.find(w => w.slug === activeSlug.value)?.title ?? '';
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') close();
}

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition name="twm">
            <div v-if="activeSlug" class="twm-backdrop" @click.self="close">
                <div class="twm-shell" role="dialog" aria-modal="true">
                    <div class="twm-header">
                        <span class="twm-title">{{ activeTitle() }}</span>
                        <button class="twm-close" @click="close" aria-label="Close">✕</button>
                    </div>
                    <div class="twm-body">
                        <component :is="resolveComponent()" v-if="activeSlug" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.twm-backdrop {
    position: fixed;
    inset: 0;
    z-index: 900;
    background: rgba(0, 0, 0, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}

.twm-shell {
    background: var(--clr-surface, #1a1a2e);
    border: 1px solid var(--clr-border);
    border-radius: 16px;
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.4);
}

.twm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--clr-border);
    flex-shrink: 0;
}

.twm-title {
    font-size: 0.9em;
    font-weight: 700;
    color: var(--clr-text);
}

.twm-close {
    background: none;
    border: none;
    color: var(--clr-text-muted);
    font-size: 1em;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 6px;
    transition: background 0.12s, color 0.12s;
}
.twm-close:hover {
    background: var(--clr-surface-2);
    color: var(--clr-text);
}

.twm-body {
    overflow-y: auto;
    flex: 1;
}

/* Transition */
.twm-enter-active, .twm-leave-active { transition: opacity 0.18s ease; }
.twm-enter-active .twm-shell, .twm-leave-active .twm-shell { transition: transform 0.18s ease, opacity 0.18s ease; }
.twm-enter-from, .twm-leave-to { opacity: 0; }
.twm-enter-from .twm-shell, .twm-leave-to .twm-shell { transform: translateY(12px); opacity: 0; }
</style>
