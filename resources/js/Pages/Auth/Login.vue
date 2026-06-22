<script setup lang="ts">
import { useForm, Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthCard from '@/Components/Auth/AuthCard.vue';

defineOptions({ layout: PublicLayout });

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign In — SBN Teaching Hub" />

    <AuthCard title="Welcome back" subtitle="Sign in to continue.">
        <template #notice>
            <strong>Free during beta.</strong> New here?
            <Link href="/register">Create a free account</Link> — it only takes a moment.
        </template>

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

            <div class="sbn-auth-field">
                <label class="sbn-label" for="password">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="sbn-input"
                    autocomplete="current-password"
                    required
                />
                <span v-if="form.errors.password" class="sbn-field-error">{{ form.errors.password }}</span>
            </div>

            <label class="sbn-auth-remember">
                <input v-model="form.remember" type="checkbox" />
                <span>Keep me signed in</span>
            </label>

            <button type="submit" class="sbn-btn sbn-btn-primary sbn-auth-submit" :disabled="form.processing">
                Sign In
            </button>
        </form>

        <div class="sbn-auth-links">
            <Link href="/forgot-password">Forgot password?</Link>
            <Link href="/register">Create an account</Link>
        </div>
    </AuthCard>
</template>
