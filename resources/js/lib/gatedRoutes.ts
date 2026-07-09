// Client-side mirror of the server-side auth gate in bootstrap/app.php
// (redirectGuestsTo) + the `auth` middleware groups in routes/web.php.
// Keep in sync with those — this only decides whether to show the auth
// modal instead of navigating; the server still enforces the real gate.
const GATED_PREFIXES = [
    '/library/',
    '/theory',
    '/account',
    '/community',
];

export function isGatedPath(path: string): boolean {
    if (GATED_PREFIXES.some((prefix) => path.startsWith(prefix))) return true;
    // /learn/{course}/play and /learn/{course}/play/{lesson} are gated;
    // /learn and /learn/{course} (catalog + detail) are public.
    if (/^\/learn\/[^/]+\/play(\/|$)/.test(path)) return true;
    return false;
}
