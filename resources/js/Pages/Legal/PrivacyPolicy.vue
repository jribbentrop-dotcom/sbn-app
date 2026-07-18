<script setup lang="ts">
import ProsePage from '@/Components/Legal/ProsePage.vue';
</script>

<template>
    <ProsePage title="Privacy Policy" updated="Last updated: [DATE]">
        <p>
            This Privacy Policy explains how Soul Bossa Nova ("SBN", "we", "us") collects, uses,
            and protects your personal data when you use our website and services, in accordance
            with the EU General Data Protection Regulation (GDPR).
        </p>

        <h2>1. Controller</h2>
        <p class="legal-block">
            [YOUR FULL LEGAL NAME]<br />
            [YOUR STREET ADDRESS]<br />
            [YOUR POSTAL CODE, CITY], Germany<br />
            Email: [YOUR EMAIL]
        </p>

        <h2>2. What Data We Collect</h2>
        <p><strong>Account data:</strong> name, email address, password (hashed), country, and
            any profile information you provide.</p>
        <p><strong>Payment data:</strong> billing details processed by our payment provider,
            [STRIPE / PAYPAL / OTHER]. We do not store full card numbers ourselves — these are
            handled directly by our processor.</p>
        <p><strong>Usage data:</strong> course progress, quiz results, practice activity, and
            interactions with learning content, used to personalize your experience.</p>
        <p><strong>Technical data:</strong> IP address, browser type, device information, and log
            data, collected automatically for security and functionality.</p>
        <p><strong>Cookies:</strong> [ Choose based on what's actually implemented: essential
            session cookies only / essential + analytics cookies via [PROVIDER, e.g.
            Plausible/Google Analytics] ].</p>

        <h2>3. Legal Basis for Processing (Art. 6 GDPR)</h2>
        <ul>
            <li><strong>Contract performance</strong> (Art. 6(1)(b)): account creation,
                subscription management, delivering course content.</li>
            <li><strong>Legitimate interest</strong> (Art. 6(1)(f)): security, fraud prevention,
                service improvement.</li>
            <li><strong>Consent</strong> (Art. 6(1)(a)): optional cookies, marketing
                communications.</li>
            <li><strong>Legal obligation</strong> (Art. 6(1)(c)): tax and invoicing records.</li>
        </ul>

        <h2>4. Third-Party Processors</h2>
        <p>We share data only as necessary with:</p>
        <ul>
            <li><strong>Payment processing:</strong> [STRIPE / PAYPAL / OTHER] — processes
                subscription payments.</li>
            <li><strong>Hosting:</strong> [YOUR HOSTING PROVIDER]</li>
            <li><strong>Analytics:</strong> [PROVIDER, or "none currently used"]</li>
            <li><strong>Email delivery:</strong> [PROVIDER, e.g. Postmark/SendGrid, if
                applicable]</li>
        </ul>
        <p>
            All processors are bound by data processing agreements in accordance with Art. 28
            GDPR. Where data is transferred outside the EU/EEA, we rely on Standard Contractual
            Clauses or an equivalent adequacy mechanism.
        </p>

        <h2>5. Data Retention</h2>
        <p>
            We retain account data for as long as your account is active. Invoice and
            tax-relevant data is retained for [10 years / per German commercial and tax law, §257
            HGB / §147 AO]. You may request deletion of your account at any time, subject to
            statutory retention obligations.
        </p>

        <h2>6. Your Rights</h2>
        <p>Under the GDPR, you have the right to:</p>
        <ul>
            <li>Access the personal data we hold about you (Art. 15)</li>
            <li>Request correction of inaccurate data (Art. 16)</li>
            <li>Request erasure ("right to be forgotten") (Art. 17)</li>
            <li>Request restriction of processing (Art. 18)</li>
            <li>Receive your data in a portable format (Art. 20)</li>
            <li>Object to processing based on legitimate interest (Art. 21)</li>
            <li>Lodge a complaint with a supervisory authority — in Germany, the data protection
                authority of your federal state, or [YOUR STATE DPA]</li>
        </ul>
        <p>To exercise any of these rights, contact us at [YOUR EMAIL].</p>

        <h2>7. Security</h2>
        <p>
            We use appropriate technical and organizational measures (encryption in transit,
            hashed passwords, access controls) to protect your data against unauthorized access,
            loss, or misuse.
        </p>

        <h2>8. Changes to This Policy</h2>
        <p>
            We may update this policy from time to time. Material changes will be communicated via
            email or a notice on the site.
        </p>
    </ProsePage>
</template>
