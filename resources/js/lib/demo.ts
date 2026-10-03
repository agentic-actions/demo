/** Where the demo points people: the package's docs, the package itself, and this app's source. */
export const links = {
    docs: 'https://agentic-actions.com',
    package: 'https://github.com/agentic-actions/laravel',
    source: 'https://github.com/agentic-actions/demo',
} as const;

/**
 * The time left until `iso` from `now`, as "23 h 59 m", "42 m" or "under a minute"; null once it has passed. Whole
 * minutes, rounded down, so it never promises more time than is left.
 */
export function timeLeft(iso: string, now: number): string | null {
    const left = new Date(iso).getTime() - now;

    if (!(left > 0)) {
        return null;
    }

    const minutes = Math.floor(left / 60_000);

    if (minutes < 1) {
        return 'under a minute';
    }

    const hours = Math.floor(minutes / 60);

    return hours > 0 ? `${hours} h ${minutes % 60} m` : `${minutes} m`;
}
