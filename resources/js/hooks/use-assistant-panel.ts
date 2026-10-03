import { useSyncExternalStore } from 'react';

const key = 'assistant-panel-open';
const listeners = new Set<() => void>();

// Used when sessionStorage is unavailable (a private window that blocks it, for example).
let fallback = false;

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

function isOpen(): boolean {
    try {
        const stored = sessionStorage.getItem(key);

        return stored === null ? fallback : stored === '1';
    } catch {
        return fallback;
    }
}

function setOpen(open: boolean): void {
    fallback = open;

    try {
        sessionStorage.setItem(key, open ? '1' : '0');
    } catch {
        // The in-memory fallback above still holds it for this page.
    }

    listeners.forEach((listener) => listener());
}

/**
 * Whether the assistant panel is open, kept per browser tab (sessionStorage), so it stays open across Inertia visits
 * and reloads without opening in every other tab. The server always renders it closed.
 */
export function useAssistantPanel(): {
    open: boolean;
    setOpen: (open: boolean) => void;
    toggle: () => void;
} {
    const open = useSyncExternalStore(subscribe, isOpen, () => false);

    return { open, setOpen, toggle: () => setOpen(!isOpen()) };
}
