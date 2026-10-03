import type { ElicitResult, WaitingElicitation } from '@agentic-actions/client';
import {
    ElicitationForm,
    type ElicitationFormProps,
} from '@agentic-actions/client/react';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * The classes of the kit's shadcn/ui Input, Label and Button, for the package's native controls. One class list serves
 * every control, so the textarea variant rides on it; color-scheme gives the date picker and the select's list the
 * dark theme too.
 */
const classNames: ElicitationFormProps['classNames'] = {
    form: 'flex flex-col gap-3 rounded-lg border bg-card p-3 text-sm shadow-xs',
    source: 'text-xs font-medium text-muted-foreground',
    message: 'font-medium text-foreground',
    field: 'grid gap-1.5',
    label: 'text-sm leading-none font-medium [&>span]:ms-0.5 [&>span]:text-red-600 dark:[&>span]:text-red-400',
    input: cn(
        'flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm dark:[color-scheme:dark]',
        'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
        'aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40',
        '[&:is(textarea)]:field-sizing-content [&:is(textarea)]:h-auto [&:is(textarea)]:min-h-16 [&:is(textarea)]:py-2',
    ),
    description: 'text-xs text-muted-foreground',
    error: 'text-xs text-red-600 dark:text-red-400',
    actions: 'flex flex-wrap gap-2 pt-1',
    submit: buttonVariants({ size: 'sm' }),
    decline: buttonVariants({ variant: 'outline', size: 'sm' }),
    cancel: buttonVariants({ variant: 'ghost', size: 'sm' }),
};

/**
 * A form the server built for a copilot call that left details out: create-task without a priority or a due date.
 * <ElicitationForm> renders the standard's params with native controls: the line naming this app, the sentence, one
 * labelled field per property, then Submit, Decline and Not now. Every string comes from the server.
 */
export default function AssistantForm({
    form,
    onAnswer,
}: {
    form: WaitingElicitation;
    onAnswer: (
        result: ElicitResult,
    ) => Promise<Record<string, string[]> | null>;
}) {
    return (
        <ElicitationForm
            params={form.params}
            labels={form.labels}
            onAnswer={onAnswer}
            classNames={classNames}
        />
    );
}
