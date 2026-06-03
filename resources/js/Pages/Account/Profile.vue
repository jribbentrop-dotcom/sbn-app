<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AccountLayout from '@/Layouts/AccountLayout.vue';

defineOptions({ layout: [PublicLayout, AccountLayout] });

const props = defineProps<{
    profile: {
        display_name: string | null;
        bio: string | null;
        avatar_url: string | null;
        public: boolean;
    };
}>();

const form = useForm({
    display_name: props.profile.display_name ?? '',
    bio: props.profile.bio ?? '',
    public: props.profile.public,
});

const avatarForm = useForm<{ avatar: File | null }>({ avatar: null });

function save() {
    form.patch('/account/profile', { preserveScroll: true });
}

function onAvatarChange(e: Event) {
    const target = e.target as HTMLInputElement;
    if (!target.files?.length) return;
    avatarForm.avatar = target.files[0];
    avatarForm.post('/account/profile/avatar', {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="sbn-page sbn-page-detail">
            <header class="sbn-account-pageheader">
                <h1>Profile</h1>
                <p class="sbn-account-subtle">How you appear in messages and the community.</p>
            </header>

            <section class="sbn-account-section">
                <h2>Avatar</h2>
                <div class="sbn-account-avatar-row">
                    <div class="sbn-account-avatar">
                        <img v-if="profile.avatar_url" :src="profile.avatar_url" alt="Your avatar" />
                        <span v-else class="sbn-account-avatar-fallback">{{ (profile.display_name ?? '?').charAt(0).toUpperCase() }}</span>
                    </div>
                    <label class="sbn-account-upload">
                        <input type="file" accept="image/*" @change="onAvatarChange" />
                        <span>Upload new</span>
                    </label>
                </div>
                <span v-if="avatarForm.errors.avatar" class="sbn-field-error">{{ avatarForm.errors.avatar }}</span>
            </section>

            <form class="sbn-account-section" @submit.prevent="save">
                <h2>Details</h2>
                <div style="margin-bottom: var(--space-4, 1rem)">
                    <label class="sbn-label">Display name</label>
                    <input v-model="form.display_name" type="text" maxlength="80" class="sbn-input" />
                    <span v-if="form.errors.display_name" class="sbn-field-error">{{ form.errors.display_name }}</span>
                </div>

                <div style="margin-bottom: var(--space-4, 1rem)">
                    <label class="sbn-label">Bio</label>
                    <textarea v-model="form.bio" rows="4" maxlength="2000" class="sbn-textarea"></textarea>
                </div>

                <label class="sbn-account-field--inline" style="margin-bottom: var(--space-4, 1rem)">
                    <input v-model="form.public" type="checkbox" />
                    <span>Show my profile to other students</span>
                </label>

                <button type="submit" class="sbn-btn sbn-btn-primary" :disabled="form.processing">Save</button>
            </form>
    </div>
</template>
