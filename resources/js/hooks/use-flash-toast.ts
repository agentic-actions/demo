import type { HttpExceptionResponse } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            toast[data.type](data.message);
        });
    }, []);

    useEffect(() => {
        return router.on('httpException', (event) => {
            const message = refusal(event.detail.response);

            if (message !== null) {
                event.preventDefault();
                toast.error(message);
            }
        });
    }, []);
}

/**
 * The line to show for a refusal the server answered in plain text (the hosted demo's "Email changes are off", "The
 * demo is full", a limit's "Try again in 58 minutes"), in place of Inertia's error dialog; for too many requests
 * without one, a general line. Null for anything else, which keeps the dialog.
 */
export function refusal(response: HttpExceptionResponse): string | null {
    const type = response.headers['content-type'] ?? '';

    if (
        type.startsWith('text/plain') &&
        typeof response.data === 'string' &&
        response.data.length > 0 &&
        response.data.length <= 300
    ) {
        return response.data;
    }

    if (response.status === 429) {
        return 'Too many tries. Wait a minute and try again.';
    }

    return null;
}
