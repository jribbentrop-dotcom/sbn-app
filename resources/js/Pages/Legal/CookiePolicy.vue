<script setup lang="ts">
import ProsePage from '@/Components/Legal/ProsePage.vue';
</script>

<template>
    <ProsePage title="Cookie Policy" updated="Last updated: [DATE]">
        <p>
            This Cookie Policy explains how Soul Bossa Nova ("SBN", "we", "us") uses cookies and
            similar technologies, in accordance with the German Telecommunications-Telemedia Data
            Protection Act (TTDSG) and the GDPR.
        </p>

        <h2>1. What Are Cookies</h2>
        <p>
            Cookies are small text files stored on your device when you visit a website. They help
            the site function, remember your preferences, and — where permitted — understand how
            the site is used.
        </p>

        <h2>2. Cookies We Use</h2>

        <h3>Strictly Necessary (no consent required)</h3>
        <p>These are essential for the site to function and cannot be switched off.</p>
        <div class="legal-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Cookie</th>
                        <th>Purpose</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Session cookie (Laravel session)</td>
                        <td>Keeps you logged in, maintains your session state</td>
                        <td>Session / [X days]</td>
                    </tr>
                    <tr>
                        <td>CSRF token</td>
                        <td>Security — protects against cross-site request forgery</td>
                        <td>Session</td>
                    </tr>
                    <tr>
                        <td>Cookie consent preference</td>
                        <td>Remembers your cookie choices</td>
                        <td>[X months/years]</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h3>Analytics (consent required)</h3>
        <p>[ Choose based on what's actually running: ]</p>
        <p><strong>Option A — none currently used:</strong><br />
            We do not currently use any analytics or tracking cookies.</p>
        <p><strong>Option B — if using an analytics provider:</strong></p>
        <div class="legal-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Cookie</th>
                        <th>Purpose</th>
                        <th>Provider</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>[_ga, _pk_id, etc.]</td>
                        <td>Understand site usage and improve the product</td>
                        <td>[PROVIDER, e.g. Plausible / Google Analytics / Fathom]</td>
                        <td>[X days/months]</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h3>Payment (consent not required — necessary for transaction)</h3>
        <div class="legal-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Cookie</th>
                        <th>Purpose</th>
                        <th>Provider</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>[Stripe/PayPal session cookies]</td>
                        <td>Fraud prevention and payment processing during checkout</td>
                        <td>[STRIPE / PAYPAL / OTHER]</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2>3. Your Consent</h2>
        <p>
            On your first visit, we ask for your consent to any non-essential cookies via a
            banner. You can:
        </p>
        <ul>
            <li>Accept all cookies</li>
            <li>Reject non-essential cookies</li>
            <li>Change your preference at any time via [the cookie settings link in the footer /
                your account settings]</li>
        </ul>
        <p>
            Strictly necessary cookies are always active, as the site cannot function without
            them.
        </p>

        <h2>4. Managing Cookies in Your Browser</h2>
        <p>
            You can also control or delete cookies through your browser settings. Note that
            blocking essential cookies may prevent parts of the site (like staying logged in) from
            working correctly.
        </p>

        <h2>5. Changes to This Policy</h2>
        <p>
            We may update this policy as our use of cookies changes. Material changes will be
            reflected here with an updated date.
        </p>
        <p>Questions? Contact us at [YOUR EMAIL].</p>
    </ProsePage>
</template>
