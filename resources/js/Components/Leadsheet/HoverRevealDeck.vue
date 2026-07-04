<script setup>
defineProps({
  /** 'score' = sticky within a normal-flow column (classic Viewer).
   *  'video' = absolutely positioned over a video container (Cinema). */
  variant: { type: String, default: 'score' },
  /** Whether the deck should be shown — driven by hover state on the larger
   *  host container (the whole video card / score column), not this element
   *  itself, since it's invisible until revealed and so can't host its own
   *  hover target while hidden. */
  visible: { type: Boolean, default: false },
});
</script>

<template>
  <div
    class="sbn-hover-deck"
    :class="[`sbn-hover-deck--${variant}`, visible ? 'is-visible' : 'is-hidden']"
  >
    <slot />
  </div>
</template>

<style scoped>
.sbn-hover-deck {
  transition: transform 0.3s var(--ease), opacity 0.3s var(--ease);
  pointer-events: auto;
}

.sbn-hover-deck.is-hidden {
  transform: translateY(120%);
  opacity: 0;
  pointer-events: none;
}

.sbn-hover-deck.is-visible {
  transform: translateY(0);
  opacity: 1;
}

/* score variant — sticky within a normal-flow column (classic Viewer).
   bottom sits ~10-15% up from the viewport edge (not flush against it) so the
   deck floats over the score rather than hugging the browser chrome. */
.sbn-hover-deck--score {
  position: sticky;
  bottom: 8vh;
  z-index: 100;
  margin-top: auto;
}

@media (max-width: 768px) {
  .sbn-hover-deck--score {
    bottom: 8vh;
  }
}

/* video variant — absolutely positioned over a video container (Cinema) */
.sbn-hover-deck--video {
  position: absolute;
  left: 12px;
  right: 12px;
  bottom: 12px;
  z-index: 20;
}
</style>
