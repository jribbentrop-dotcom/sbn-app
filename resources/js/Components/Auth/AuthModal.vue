<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { onMounted, onUnmounted, watch } from 'vue';
import { useAuthModal } from '@/composables/useAuthModal';

const { activeMode, redirectTo, close } = useAuthModal();

const loginForm = useForm({
    email: '',
    password: '',
    remember: false,
});

const registerForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submitLogin() {
    loginForm.transform((data) => ({ ...data, redirect: redirectTo.value })).post('/login', {
        preserveScroll: true,
        onSuccess: () => close(),
        onFinish: () => loginForm.reset('password'),
    });
}

function submitRegister() {
    registerForm.transform((data) => ({ ...data, redirect: redirectTo.value })).post('/register', {
        preserveScroll: true,
        onSuccess: () => close(),
        onFinish: () => registerForm.reset('password', 'password_confirmation'),
    });
}

function switchTo(mode: 'login' | 'register') {
    loginForm.clearErrors();
    registerForm.clearErrors();
    activeMode.value = mode;
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') close();
}

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));

watch(activeMode, (mode) => {
    document.body.style.overflow = mode ? 'hidden' : '';
});
</script>

<template>
    <Teleport to="body">
        <Transition name="sbn-auth-modal">
            <div v-if="activeMode" class="sbn-auth-backdrop" @click.self="close">
                <div class="sbn-auth-card" role="dialog" aria-modal="true">
                    <button class="sbn-auth-close" @click="close" aria-label="Close">✕</button>

                    <div class="sbn-auth-logo">
                        <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                            <defs>
                                <linearGradient id="sbn-auth-modal-lg" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                                    <stop offset="0%" stop-color="#f39c12" />
                                    <stop offset="100%" stop-color="#e74c3c" />
                                </linearGradient>
                            </defs>
                            <rect width="32" height="32" rx="8" fill="url(#sbn-auth-modal-lg)" />
                            <path d="M8 22V10l8 6-8 6z" fill="#fff" opacity="0.9" />
                            <path d="M16 22V10l8 6-8 6z" fill="#fff" opacity="0.6" />
                        </svg>
                        <span>SBN <span class="sbn-auth-accent">Hub</span></span>
                    </div>

                    <template v-if="activeMode === 'login'">
                        <h1 class="sbn-auth-title">Welcome back</h1>
                        <p class="sbn-auth-sub">Sign in to continue.</p>

                        <div class="sbn-auth-notice">
                            <strong>Free during beta.</strong> New here?
                            <a href="#" @click.prevent="switchTo('register')">Create a free account</a> — it only takes a moment.
                        </div>

                        <form @submit.prevent="submitLogin">
                            <div class="sbn-auth-field">
                                <label class="sbn-label" for="modal-login-email">Email</label>
                                <input
                                    id="modal-login-email"
                                    v-model="loginForm.email"
                                    type="email"
                                    class="sbn-input"
                                    autocomplete="email"
                                    autofocus
                                    required
                                />
                                <span v-if="loginForm.errors.email" class="sbn-field-error">{{ loginForm.errors.email }}</span>
                            </div>

                            <div class="sbn-auth-field">
                                <label class="sbn-label" for="modal-login-password">Password</label>
                                <input
                                    id="modal-login-password"
                                    v-model="loginForm.password"
                                    type="password"
                                    class="sbn-input"
                                    autocomplete="current-password"
                                    required
                                />
                                <span v-if="loginForm.errors.password" class="sbn-field-error">{{ loginForm.errors.password }}</span>
                            </div>

                            <label class="sbn-auth-remember">
                                <input v-model="loginForm.remember" type="checkbox" />
                                <span>Keep me signed in</span>
                            </label>

                            <button type="submit" class="sbn-btn sbn-btn-primary sbn-auth-submit" :disabled="loginForm.processing">
                                Sign In
                            </button>
                        </form>

                        <div class="sbn-auth-links">
                            <a href="/forgot-password">Forgot password?</a>
                            <a href="#" @click.prevent="switchTo('register')">Create an account</a>
                        </div>
                    </template>

                    <template v-else>
                        <h1 class="sbn-auth-title">Create your free account</h1>
                        <p class="sbn-auth-sub">Join the SBN Teaching Hub.</p>

                        <div class="sbn-auth-notice">
                            <strong>We're in beta.</strong> Everything is free right now — we
                            just ask you to create an account so we can keep in touch and shape
                            the app around how you learn. No payment, no spam.
                        </div>

                        <form @submit.prevent="submitRegister">
                            <div class="sbn-auth-field">
                                <label class="sbn-label" for="modal-register-name">Name</label>
                                <input
                                    id="modal-register-name"
                                    v-model="registerForm.name"
                                    type="text"
                                    class="sbn-input"
                                    autocomplete="name"
                                    maxlength="255"
                                    autofocus
                                    required
                                />
                                <span v-if="registerForm.errors.name" class="sbn-field-error">{{ registerForm.errors.name }}</span>
                            </div>

                            <div class="sbn-auth-field">
                                <label class="sbn-label" for="modal-register-email">Email</label>
                                <input
                                    id="modal-register-email"
                                    v-model="registerForm.email"
                                    type="email"
                                    class="sbn-input"
                                    autocomplete="email"
                                    required
                                />
                                <span v-if="registerForm.errors.email" class="sbn-field-error">{{ registerForm.errors.email }}</span>
                            </div>

                            <div class="sbn-auth-field">
                                <label class="sbn-label" for="modal-register-password">Password</label>
                                <input
                                    id="modal-register-password"
                                    v-model="registerForm.password"
                                    type="password"
                                    class="sbn-input"
                                    autocomplete="new-password"
                                    required
                                />
                                <span v-if="registerForm.errors.password" class="sbn-field-error">{{ registerForm.errors.password }}</span>
                            </div>

                            <div class="sbn-auth-field">
                                <label class="sbn-label" for="modal-register-password-confirmation">Confirm password</label>
                                <input
                                    id="modal-register-password-confirmation"
                                    v-model="registerForm.password_confirmation"
                                    type="password"
                                    class="sbn-input"
                                    autocomplete="new-password"
                                    required
                                />
                            </div>

                            <button type="submit" class="sbn-btn sbn-btn-primary sbn-auth-submit" :disabled="registerForm.processing">
                                Create account
                            </button>
                        </form>

                        <div class="sbn-auth-links">
                            <span></span>
                            <a href="#" @click.prevent="switchTo('login')">Already have an account?</a>
                        </div>
                    </template>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.sbn-auth-backdrop {
    position: fixed;
    inset: 0;
    /* Above the sticky header and its mega-menu panels (up to 999999 in
       mega-menu.css) — this is a full app takeover, nothing should show above it. */
    z-index: 1000000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: clamp(1rem, 6vw, 3rem) 1rem;
    background: rgba(10, 10, 15, 0.55);
    backdrop-filter: blur(3px);
    overflow-y: auto;
}

.sbn-auth-card {
    position: relative;
    width: 100%;
    max-width: 400px;
    background: var(--clr-surface, #fff);
    border: 1px solid var(--clr-border, #e2e8f0);
    border-radius: var(--radius-lg, 16px);
    padding: 40px 36px;
    box-shadow:
        0 1px 1px rgba(0, 0, 0, 0.04),
        0 24px 64px rgba(0, 0, 0, 0.4);
    margin: auto;
}

.sbn-auth-close {
    position: absolute;
    top: 14px;
    right: 14px;
    background: none;
    border: none;
    color: var(--clr-text-muted, #5a5a5a);
    font-size: 1em;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background 0.12s, color 0.12s;
}
.sbn-auth-close:hover {
    background: var(--clr-surface-2, #f1f1f1);
    color: var(--clr-text);
}

.sbn-auth-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 28px;
}

.sbn-auth-logo svg {
    width: 36px;
    height: 36px;
}

.sbn-auth-logo span {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--clr-text);
}

.sbn-auth-accent {
    color: var(--clr-accent, #f39c12);
}

.sbn-auth-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0 0 4px;
    color: var(--clr-text);
}

.sbn-auth-sub {
    color: var(--clr-text-muted, #5a5a5a);
    font-size: 13px;
    margin: 0 0 24px;
}

.sbn-auth-modal-enter-active,
.sbn-auth-modal-leave-active {
    transition: opacity 0.18s ease;
}
.sbn-auth-modal-enter-active .sbn-auth-card,
.sbn-auth-modal-leave-active .sbn-auth-card {
    transition: transform 0.18s ease, opacity 0.18s ease;
}
.sbn-auth-modal-enter-from,
.sbn-auth-modal-leave-to {
    opacity: 0;
}
.sbn-auth-modal-enter-from .sbn-auth-card,
.sbn-auth-modal-leave-to .sbn-auth-card {
    transform: translateY(12px);
    opacity: 0;
}
</style>

<!-- Unscoped: shares field/notice/link styling with the legacy AuthCard-based
     full pages (Auth/Login.vue, Auth/Register.vue) which remain for direct
     links, gated-route redirects, and SEO. -->
<style>
.sbn-auth-notice {
    background: color-mix(in srgb, var(--clr-accent, #f39c12) 9%, transparent);
    border: 1px solid color-mix(in srgb, var(--clr-accent, #f39c12) 28%, transparent);
    color: var(--clr-text, #1a1a1a);
    padding: 12px 14px;
    border-radius: var(--radius, 8px);
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 22px;
}

.sbn-auth-notice strong {
    font-weight: 700;
}

.sbn-auth-field {
    margin-bottom: 18px;
}

.sbn-auth-remember {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 22px;
    font-size: 13px;
    color: var(--clr-text-muted, #5a5a5a);
    cursor: pointer;
}

.sbn-auth-submit {
    width: 100%;
    justify-content: center;
}

.sbn-auth-links {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 20px;
    font-size: 13px;
}

.sbn-auth-links a {
    color: var(--clr-accent-dim, #e67e22);
    text-decoration: none;
    font-weight: 600;
}

.sbn-auth-links a:hover {
    text-decoration: underline;
}
</style>
