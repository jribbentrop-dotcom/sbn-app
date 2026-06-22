<script setup lang="ts">
import { useForm, Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthCard from '@/Components/Auth/AuthCard.vue';

defineOptions({ layout: PublicLayout });

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Create Account — SBN Teaching Hub" />

    <AuthCard title="Create your free account" subtitle="Join the SBN Teaching Hub.">
        <template #notice>
            <strong>We're in beta.</strong> Everything is free right now — we
            just ask you to create an account so we can keep in touch and shape
            the app around how you learn. No payment, no spam.
        </template>

        <form @submit.prevent="submit">
            <div class="sbn-auth-field">
                <label class="sbn-label" for="name">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="sbn-input"
                    autocomplete="name"
                    maxlength="255"
                    autofocus
                    required
                />
                <span v-if="form.errors.name" class="sbn-field-error">{{ form.errors.name }}</span>
            </div>

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
                <label class="sbn-label" for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="sbn-input"
                    autocomplete="new-password"
                    required
                />
                <span v-if="form.errors.password" class="sbn-field-error">{{ form.errors.password }}</span>
            </div>

            <div class="sbn-auth-field">
                <label class="sbn-label" for="password_confirmation">Confirm password</label>
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
                Create account
            </button>
        </form>

        <div class="sbn-auth-links">
            <span></span>
            <Link href="/login">Already have an account?</Link>
        </div>
    </AuthCard>
</template>
