<script setup lang="ts">
import { useForm, Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthCard from '@/Components/Auth/AuthCard.vue';

defineOptions({ layout: PublicLayout });

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

function submit() {
    form.post('/forgot-password');
}
</script>

<template>
    <Head title="Reset Password — SBN Teaching Hub" />

    <AuthCard
        title="Forgot your password?"
        subtitle="Enter your email and we'll send you a reset link."
    >
        <div v-if="status" class="sbn-auth-status">{{ status }}</div>

        <form @submit.prevent="submit">
            <div class="sbn-auth-field">
                <label class="sbn-label" for="email">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="sbn-input"
                    autocomplete="email"
                    autofocus
                    required
                />
                <span v-if="form.errors.email" class="sbn-field-error">{{ form.errors.email }}</span>
            </div>

            <button type="submit" class="sbn-btn sbn-btn-primary sbn-auth-submit" :disabled="form.processing">
                Send reset link
            </button>
        </form>

        <div class="sbn-auth-links">
            <span></span>
            <Link href="/login">Back to sign in</Link>
        </div>
    </AuthCard>
</template>
