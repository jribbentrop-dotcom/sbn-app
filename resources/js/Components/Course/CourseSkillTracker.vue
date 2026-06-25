<script setup lang="ts">
/**
 * Compact self-report skill tracker shown in the lesson player.
 *
 * Lists the skill nodes the current course teaches and lets a signed-in student
 * tick them off as they go. Mirrors the toggle on /account/skills and the course
 * detail page — all three POST to the same account.skills.toggle endpoint, so a
 * skill marked here shows as done everywhere. Collapsible; defaults open.
 */
import { reactive, ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';

interface SkillRef {
  slug: string;
  title: string;
  branch: string;
  grade: number | null;
  iconKey: string | null;
  iconPath: string | null;
  done: boolean;
}

const props = defineProps<{ skills: SkillRef[] }>();

const open = ref(true);

const done = reactive<Record<string, true>>(
  Object.fromEntries(props.skills.filter(s => s.done).map(s => [s.slug, true]))
);
const pending = reactive<Record<string, true>>({});

const doneCount = computed(() => props.skills.filter(s => !!done[s.slug]).length);

function toggle(skill: SkillRef) {
  if (pending[skill.slug]) return;
  pending[skill.slug] = true;

  const wasDone = !!done[skill.slug];
  wasDone ? delete done[skill.slug] : (done[skill.slug] = true);

  fetch(`/account/skills/${skill.slug}/toggle`, {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' },
  }).catch(() => {
    wasDone ? (done[skill.slug] = true) : delete done[skill.slug];
  }).finally(() => {
    delete pending[skill.slug];
  });
}
</script>

<template>
  <section v-if="skills.length" class="vC-skills">
    <button type="button" class="vC-skills-head" :aria-expanded="open" @click="open = !open">
      <span class="vC-skills-title">Skills in this course</span>
      <span class="vC-skills-count">{{ doneCount }}/{{ skills.length }}</span>
      <svg class="vC-skills-chevron" :class="{ 'is-open': open }" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="m6 9 6 6 6-6"/>
      </svg>
    </button>

    <div v-show="open" class="vC-skills-body">
      <p class="vC-skills-hint">Tick a skill as you get it — it syncs to <Link href="/account/skills">My Skills</Link>.</p>
      <div class="vC-skills-grid">
        <button
          v-for="skill in skills"
          :key="skill.slug"
          type="button"
          class="vC-skill"
          :class="{ 'is-done': !!done[skill.slug], 'is-pending': !!pending[skill.slug] }"
          :aria-pressed="!!done[skill.slug]"
          :aria-label="(done[skill.slug] ? 'Mark incomplete: ' : 'Mark complete: ') + skill.title"
          @click="toggle(skill)"
        >
          <span class="vC-skill-icon">
            <SkillIcon :icon-path="skill.iconPath" :icon-key="skill.iconKey" :branch="skill.branch" :size="17" />
          </span>
          <span class="vC-skill-title">{{ skill.title }}</span>
          <span class="vC-skill-check" aria-hidden="true">
            <svg v-if="done[skill.slug]" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 6 9 17l-5-5"/>
            </svg>
          </span>
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.vC-skills {
  margin: 28px 0 8px;
  border: 1px solid var(--clr-border, #e5e5e5);
  border-radius: var(--radius, 8px);
  background: var(--clr-surface-2, #fafafa);
  overflow: hidden;
}
.vC-skills-head {
  display: flex; align-items: center; gap: 10px;
  width: 100%; padding: 12px 14px;
  border: 0; background: transparent; cursor: pointer;
  text-align: left; color: var(--clr-text, #1a1a1a);
}
.vC-skills-title { font-size: 14px; font-weight: 700; }
.vC-skills-count {
  margin-left: auto;
  font-size: 12px; font-weight: 600;
  color: var(--clr-text-muted, #888);
  font-variant-numeric: tabular-nums;
}
.vC-skills-chevron { color: var(--clr-text-muted, #888); transition: transform 0.15s; }
.vC-skills-chevron.is-open { transform: rotate(180deg); }
.vC-skills-body { padding: 0 14px 14px; }
.vC-skills-hint {
  font-size: 12px; color: var(--clr-text-muted, #888);
  margin: 0 0 10px; line-height: 1.5;
}
.vC-skills-hint a { color: var(--cat-text, #b8860b); text-decoration: underline; }
.vC-skills-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 6px;
}
.vC-skill {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 10px;
  border: 1px solid var(--clr-border, #e5e5e5);
  border-radius: 7px;
  background: var(--clr-white, #fff);
  cursor: pointer; text-align: left;
  color: var(--clr-text, #1a1a1a);
  transition: border-color 0.15s, background 0.15s, opacity 0.1s;
}
.vC-skill:hover { border-color: var(--cat-border, #b8860b); }
.vC-skill.is-done {
  background: color-mix(in srgb, var(--cat-text, #b8860b) 8%, transparent);
  border-color: color-mix(in srgb, var(--cat-text, #b8860b) 40%, transparent);
}
.vC-skill.is-pending { opacity: 0.6; pointer-events: none; }
.vC-skill-icon { flex-shrink: 0; color: var(--clr-text-muted, #888); display: flex; }
.vC-skill.is-done .vC-skill-icon { color: var(--cat-text, #b8860b); }
.vC-skill-title { flex: 1; font-size: 12.5px; line-height: 1.3; }
.vC-skill-check {
  flex-shrink: 0; width: 14px; height: 14px;
  display: flex; align-items: center; justify-content: center;
  color: var(--cat-text, #b8860b);
}
</style>
