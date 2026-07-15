<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useAuthModal } from '@/composables/useAuthModal';
const year = new Date().getFullYear();
const { open: openAuthModal } = useAuthModal();
</script>

<template>
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-columns">

            <!-- Brand -->
            <div class="footer-brand">
                <Link href="/" class="footer-logo" aria-label="Soul Bossa Nova — home">
                    <img src="/images/soulbossanova.png" alt="Soul Bossa Nova">
                </Link>
                <p>An online teaching hub for the art of bossa nova and jazz guitar.</p>
            </div>

            <!-- Tools -->
            <div>
                <h5>Tools</h5>
                <Link href="/admin/leadsheets">Leadsheet Editor</Link>
                <Link href="/library/chords">Chord Library</Link>
                <Link href="/library/progressions">Progression Builder</Link>
                <a href="#">Analysis</a>
                <Link href="/library/chords/rain">Chord Rain (parked)</Link>
                <Link href="/grades/slider">Grades Slider (parked)</Link>
            </div>

            <!-- Learn -->
            <div>
                <h5>Learn</h5>
                <Link href="/library/theory">Theory Widgets</Link>
                <Link href="/learn">Lesson Paths</Link>
                <Link href="/grades">Find Your Level</Link>
                <Link href="/library/songs">Song Catalog</Link>
                <a href="#">Bossa Foundations</a>
            </div>

            <!-- Hub -->
            <div>
                <h5>Hub</h5>
                <a href="#">Pricing</a>
                <Link href="/about">About</Link>
                <Link href="/contact">Contact</Link>
                <a href="/login" @click.prevent="openAuthModal('login')">Sign in</a>
            </div>

        </div>

        <div class="footer-bottom-bar">
            <span>&copy; {{ year }} Soul Bossa Nova Teaching Hub</span>
            <span class="footer-legal">
                <Link href="/impressum">Impressum</Link>
                <Link href="/privacy-policy">Privacy Policy</Link>
                <Link href="/terms">Terms of Service</Link>
            </span>
        </div>
    </div>
</footer>
</template>

<style scoped>
.site-footer {
    background: var(--clr-bg-card) !important;
    color: var(--clr-text) !important;
    border-top: 1px solid var(--clr-line);
    padding: 54px 0 36px !important;
    margin-top: 80px !important;
}

.footer-inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 24px;
}

.footer-columns {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 1fr;
    gap: 32px;
    margin-bottom: 40px;
}

.footer-brand p {
    color: var(--clr-text-dim);
    font-size: .9rem;
    margin-top: 14px;
    max-width: 260px;
    line-height: 1.55;
}

.footer-logo {
    display: inline-flex;
    align-items: center;
    text-decoration: none;
}
.footer-logo img {
    height: 70px;
    width: auto;
}

.footer-columns h5 {
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--clr-text-dim);
    margin-bottom: 16px;
    font-weight: 600;
}

.footer-columns a,
.footer-columns :deep(a) {
    display: block;
    color: var(--clr-text-dim);
    font-size: .92rem;
    padding: 5px 0;
    transition: color .15s;
    text-decoration: none;
}
.footer-columns a:hover,
.footer-columns :deep(a:hover) {
    color: var(--clr-text);
}

.footer-bottom-bar {
    padding-top: 24px;
    border-top: 1px solid var(--clr-line);
    color: var(--clr-text-dim);
    font-size: .84rem;
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}

.footer-legal {
    display: flex;
    gap: 20px;
}
.footer-legal a,
.footer-legal :deep(a) {
    display: inline;
    color: var(--clr-text-dim);
    text-decoration: none;
    transition: color .15s;
    padding: 0;
    font-size: .84rem;
}
.footer-legal a:hover,
.footer-legal :deep(a:hover) {
    color: var(--clr-text);
}

@media (max-width: 900px) {
    .footer-columns {
        grid-template-columns: 1fr 1fr;
    }
    .footer-brand {
        grid-column: 1 / -1;
    }
}

@media (max-width: 600px) {
    .footer-columns {
        grid-template-columns: 1fr;
    }
    .footer-bottom-bar {
        flex-direction: column;
        gap: 8px;
    }
}
</style>
