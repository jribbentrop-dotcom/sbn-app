<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthCard from '@/Components/Auth/AuthCard.vue';

defineOptions({ layout: PublicLayout });

const props = defineProps<{
    email: string;
    token: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Choose a New Password — SBN Teaching Hub" />

    <AuthCard title="Choose a new password" subtitle="Set a new password for your account.">
        <form @submit.prevent="submit">
            <div class="sbn-auth-field">
                <label class="sbn-label" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="sbn-input"
                    autocomplete="email"
                    required
                />
                <span v-if="form.errors.email" class="sbn-field-error">{{ form.errors.email }}</span>
            </div>

            <div class="sbn-auth-field">
                <label class="sbn-label" for="password">New password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="sbn-input"
                    autocomplete="new-password"
                    autofocus
                    required
                />
                <span v-if="form.errors.password" class="sbn-field-error">{{ form.errors.password }}</span>
            </div>

            <div class="sbn-auth-field">
                <label class="sbn-label" for="password_confirmation">Confirm new password</label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="sbn-input"
                    autocomplete="new-password"
                    required
                />
            </div>

            <button type="submit" class="sbn-btn sbn-btn-primary sbn-auth-submit" :disabled="form.processing">
                Reset password
            </button>
        </form>
    </AuthCard>
</template>
