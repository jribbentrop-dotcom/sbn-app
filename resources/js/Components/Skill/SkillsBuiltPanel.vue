<script setup lang="ts">
import SkillIcon from '@/Components/Skill/SkillIcon.vue';

export interface SkillRef {
    slug: string;
    title: string;
    branch: string;
    grade: number | null;
    icon_key: string | null;
    icon_path: string | null;
    completed: boolean;
}

const props = withDefaults(defineProps<{
    skills: SkillRef[];
    heading?: string;
    /** compact = horizontal hero strip (icon pill + label, no grade/wrap) */
    compact?: boolean;
}>(), {
    heading: 'Skills this builds',
    compact: false,
});

const completedCount = props.skills.filter(s => s.completed).length;
</script>

<template>
    <!-- ── Compact: right-side hero icons ─────────────────────────────── -->
    <div v-if="skills.length && compact" class="sbn-skills-hero-strip">
        <ul class="sbn-skills-hero-list">
            <li
                v-for="skill in skills"
                :key="skill.slug"
                class="sbn-skills-hero-icon"
                :class="{ 'is-done': skill.completed }"
                :data-branch="skill.branch"
            >
                <SkillIcon
                    :icon-path="skill.icon_path"
                    :icon-key="skill.icon_key"
                    :branch="skill.branch"
                    :size="44"
                />
                <span class="sbn-skills-hero-tooltip">{{ skill.title }}</span>
            </li>
        </ul>
    </div>

    <!-- ── Full: wrapping tile grid ───────────────────────────────────── -->
    <div v-else-if="skills.length" class="sbn-skills-built-panel">
        <h3 class="sbn-skills-built-heading">{{ heading }}</h3>
        <p v-if="completedCount > 0" class="sbn-skills-built-progress">
            {{ completedCount }} of {{ skills.length }} completed
        </p>
        <ul class="sbn-skills-built-list">
            <li
                v-for="skill in skills"
                :key="skill.slug"
                class="sbn-skills-built-card"
                :class="{ 'is-done': skill.completed }"
                :data-branch="skill.branch"
            >
                <span v-if="skill.completed" class="sbn-skills-built-done" title="You've built this skill">✓</span>
                <span class="sbn-skills-built-icon">
                    <SkillIcon
                        :icon-path="skill.icon_path"
                        :icon-key="skill.icon_key"
                        :branch="skill.branch"
                        :size="22"
                    />
                </span>
                <span class="sbn-skills-built-title">{{ skill.title }}</span>
                <span v-if="skill.grade" class="sbn-skills-built-grade">G{{ skill.grade }}</span>
            </li>
        </ul>
    </div>
</template>
