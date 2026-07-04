import { ref } from 'vue';

/**
 * Hover-only show/hide state for a transport deck overlay. Bind onMouseEnter/
 * onMouseLeave to the *larger* host container (the whole video card, or the
 * whole score column) rather than the deck itself — the deck is invisible
 * until revealed, so it can't host a hover target while hidden. Shared by the
 * classic leadsheet Viewer's score overlay and Cinema's video overlay.
 */
export function useHoverRevealTransport() {
  const transportHovered = ref(false);

  function onMouseEnter() {
    transportHovered.value = true;
  }

  function onMouseLeave() {
    transportHovered.value = false;
  }

  return { transportHovered, onMouseEnter, onMouseLeave };
}
