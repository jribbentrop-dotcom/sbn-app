import { ref } from 'vue';

export type AuthModalMode = 'login' | 'register';

const activeMode = ref<AuthModalMode | null>(null);
// Where to send the user after a successful login/register, when the modal
// was opened from a gated link (e.g. "Start learning" on a course page)
// rather than a plain nav link.
const redirectTo = ref<string | null>(null);

export function useAuthModal() {
    function open(mode: AuthModalMode = 'login', options?: { redirectTo?: string }) {
        activeMode.value = mode;
        redirectTo.value = options?.redirectTo ?? null;
    }
    function close() {
        activeMode.value = null;
        redirectTo.value = null;
    }
    return { activeMode, redirectTo, open, close };
}
