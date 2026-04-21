<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
const year = new Date().getFullYear();
</script>

<template>
<footer class="site-footer">
    <div class="footer-inner flex flex-col md:flex-row justify-between items-center gap-4">
        <div class="footer-copyright text-sm text-white/70">
            <p>&copy; {{ year }} Soul Bossa Nova. Alle Rechte vorbehalten.</p>
        </div>
        
        <nav class="footer-navigation" aria-label="Footer Menu">
            <ul class="footer-menu flex gap-6 text-sm">
                <li><Link href="/imprint" class="text-white/70 hover:text-white transition">Imprint</Link></li>
                <li><Link href="/privacy" class="text-white/70 hover:text-white transition">Privacy Policy</Link></li>
                <li><Link href="/terms" class="text-white/70 hover:text-white transition">Terms of Service</Link></li>
            </ul>
        </nav>
    </div>
</footer>
</template>
