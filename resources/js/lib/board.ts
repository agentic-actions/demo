import type { TaskPriority, TaskStatus } from '@/types';

export const statuses: { value: TaskStatus; label: string }[] = [
    { value: 'todo', label: 'To do' },
    { value: 'doing', label: 'Doing' },
    { value: 'done', label: 'Done' },
];

export const priorities: { value: TaskPriority; label: string }[] = [
    { value: 'high', label: 'High' },
    { value: 'normal', label: 'Normal' },
    { value: 'low', label: 'Low' },
];

/**
 * Options for a field check. A role that may not run the action gets a 403 from Precognition too, and Inertia rethrows
 * anything but a 422 as an unhandled rejection. Submitting shows that refusal, so the check stays quiet about it.
 */
export const fieldCheck = { onForbidden: () => null };

/** Radix Select cannot hold an empty value, so "nobody" and "no project" travel as this and become null. */
export const NONE = '__none';

export function statusLabel(status: TaskStatus): string {
    return statuses.find((option) => option.value === status)?.label ?? status;
}

/** "Sep 30" for a YYYY-MM-DD date, read as a calendar day in the viewer's time zone. */
export function formatDueDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
    }).format(new Date(year, month - 1, day));
}
