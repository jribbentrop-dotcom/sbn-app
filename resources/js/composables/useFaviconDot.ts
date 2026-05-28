import { watch, type Ref } from 'vue';

let baseHref: string | null = null;
let dotHref: string | null = null;

function getOrCreateLink(): HTMLLinkElement {
    let link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]');
    if (!link) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.head.appendChild(link);
    }
    if (baseHref === null) {
        baseHref = link.href || '/favicon.ico';
    }
    return link;
}

function buildDot(): string {
    if (dotHref) return dotHref;
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--clr-accent').trim() || '#e85d2f';
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><image href="${baseHref}" width="32" height="32"/><circle cx="24" cy="8" r="7" fill="${accent}" stroke="white" stroke-width="2"/></svg>`;
    dotHref = 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
    return dotHref;
}

export function useFaviconDot(unread: Ref<number>) {
    watch(
        unread,
        (n) => {
            const link = getOrCreateLink();
            link.href = n > 0 ? buildDot() : (baseHref ?? '/favicon.ico');
        },
        { immediate: true }
    );
}
