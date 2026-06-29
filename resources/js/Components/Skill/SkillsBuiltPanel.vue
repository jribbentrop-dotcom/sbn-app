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
}>(), {
    heading: 'Skills this builds',
});

const completedCount = props.skills.filter(s => s.completed).length;
</script>

<template>
    <div v-if="skills.length" class="sbn-skills-built-panel">
        <h3 class="sbn-skills-built-heading">{{ heading }}</h3>
        <p v-if="completedCount > 0" class="sbn-skills-built-progress">
            You've built {{ completedCount }} of {{ skills.length }}
        </p>
        <ul class="sbn-skills-built-list">
            <li v-for="skill in skills" :key="skill.slug" class="sbn-skills-built-card">
                <!-- TODO: link to node landing page when built -->
                <span class="sbn-skills-built-icon">
                    <SkillIcon
                        :icon-path="skill.icon_path"
                        :icon-key="skill.icon_key"
                        :branch="skill.branch"
                        :size="20"
                    />
                </span>
                <span class="sbn-skills-built-title">{{ skill.title }}</span>
                <span v-if="skill.grade" class="sbn-skills-built-grade">G{{ skill.grade }}</span>
                <span v-if="skill.completed" class="sbn-skills-built-done" title="You've built this skill">✓</span>
            </li>
        </ul>
    </div>
</template>
