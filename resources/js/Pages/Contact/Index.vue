<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

const form = useForm({
    name: '',
    email: '',
    subject: '',
    message: '',
    website: '', // honeypot — must stay empty
});

function submit() {
    form.post('/contact', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Contact — Soul Bossa Nova" />

    <main class="sbn-page sbn-contact">
        <div class="sbn-contact-header">
            <h1 class="sbn-contact-title">Get in touch</h1>
            <p class="sbn-contact-subtitle">
                Questions about a course, a product, or a custom arrangement? Send a message and we'll reply soon.
            </p>
        </div>

        <div class="sbn-contact-grid">
            <section class="sbn-contact-form-wrap">
                <p v-if="form.recentlySuccessful" class="sbn-contact-success" role="status">
                    Thanks for reaching out — we'll get back to you soon.
                </p>

                <form class="sbn-contact-form" @submit.prevent="submit">
                    <!-- Honeypot: hidden from users, catches bots. Keep empty. -->
                    <div class="sbn-hp" aria-hidden="true">
                        <label>Website
                            <input v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
                        </label>
                    </div>

                    <div class="sbn-auth-field">
                        <label class="sbn-label" for="name">Name</label>
                        <input id="name" v-model="form.name" type="text" class="sbn-input" autocomplete="name" required />
                        <span v-if="form.errors.name" class="sbn-field-error">{{ form.errors.name }}</span>
                    </div>

                    <div class="sbn-auth-field">
                        <label class="sbn-label" for="email">Email</label>
                        <input id="email" v-model="form.email" type="email" class="sbn-input" autocomplete="email" required />
                        <span v-if="form.errors.email" class="sbn-field-error">{{ form.errors.email }}</span>
                    </div>

                    <div class="sbn-auth-field">
                        <label class="sbn-label" for="subject">Subject</label>
                        <input id="subject" v-model="form.subject" type="text" class="sbn-input" required />
                        <span v-if="form.errors.subject" class="sbn-field-error">{{ form.errors.subject }}</span>
                    </div>

                    <div class="sbn-auth-field">
                        <label class="sbn-label" for="message">Message</label>
                        <textarea id="message" v-model="form.message" class="sbn-input sbn-contact-textarea" rows="6" required></textarea>
                        <span v-if="form.errors.message" class="sbn-field-error">{{ form.errors.message }}</span>
                    </div>

                    <button type="submit" class="sbn-btn sbn-btn-primary" :disabled="form.processing">
                        {{ form.processing ? 'Sending…' : 'Send message' }}
                    </button>
                </form>
            </section>

            <aside class="sbn-contact-aside">
                <h2 class="sbn-contact-aside-title">Other ways to reach us</h2>
                <p class="sbn-contact-aside-text">
                    Prefer email? Write to us directly at
                    <a href="mailto:hello@soulbossanova.com">hello@soulbossanova.com</a>.
                </p>
                <p class="sbn-contact-aside-text">
                    We typically reply within a couple of business days.
                </p>
            </aside>
        </div>
    </main>
</template>

<style scoped>
.sbn-contact {
    max-width: 960px;
    margin: 0 auto;
    padding: 48px 20px 80px;
}
.sbn-contact-header { text-align: center; margin-bottom: 40px; }
.sbn-contact-title { font-size: 2rem; font-weight: 700; margin: 0 0 10px; }
.sbn-contact-subtitle { color: var(--sbn-text); max-width: 560px; margin: 0 auto; line-height: 1.55; }

.sbn-contact-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    gap: 40px;
    align-items: start;
}
@media (max-width: 768px) {
    .sbn-contact-grid { grid-template-columns: 1fr; gap: 28px; }
}

.sbn-contact-form { display: flex; flex-direction: column; gap: 16px; }
/* Honeypot — visually hidden but still in the DOM for bots to fill */
.sbn-hp {
    position: absolute;
    left: -9999px;
    width: 1px;
    height: 1px;
    overflow: hidden;
}
.sbn-contact-textarea { resize: vertical; min-height: 140px; }

.sbn-contact-success {
    background: color-mix(in srgb, var(--sbn-accent, #2e7d32) 12%, transparent);
    border: 1px solid color-mix(in srgb, var(--sbn-accent, #2e7d32) 40%, transparent);
    color: var(--sbn-dark);
    border-radius: var(--sbn-radius-sm, 8px);
    padding: 12px 16px;
    margin-bottom: 20px;
}

.sbn-contact-aside {
    background: color-mix(in srgb, var(--sbn-border) 40%, transparent);
    border-radius: var(--sbn-radius, 12px);
    padding: 24px;
}
.sbn-contact-aside-title { font-size: 1.05rem; font-weight: 700; margin: 0 0 12px; }
.sbn-contact-aside-text { color: var(--sbn-text); line-height: 1.55; margin: 0 0 12px; }
.sbn-contact-aside-text a { color: var(--sbn-dark); font-weight: 600; }
</style>
