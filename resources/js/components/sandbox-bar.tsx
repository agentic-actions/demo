import { Form, router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Link as LinkIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useClipboard } from '@/hooks/use-clipboard';
import { timeLeft } from '@/lib/demo';
import { as as viewAs, store as startDemo } from '@/routes/demo';
import type { DemoPersona } from '@/types';

/**
 * The hosted demo's bar above every signed-in page: how long the visitor's sandbox has left, and a menu to sign in as
 * another of its people (Marcus, Vera, Gus) or back as the visitor, and to copy the link back into the sandbox, the
 * only way in once this browser's session ends. Once the sandbox has expired, it offers a new one instead. Renders
 * nothing off the hosted demo, or for someone outside a sandbox.
 */
export function SandboxBar() {
    const { demo } = usePage().props;
    const [now, setNow] = useState(() => Date.now());
    const [, copy] = useClipboard();

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 30_000);

        return () => window.clearInterval(timer);
    }, []);

    if (!demo.hosted || demo.expires_at === null) {
        return null;
    }

    const left = timeLeft(demo.expires_at, now);
    const current = demo.personas.find((persona) => persona.current);

    return (
        <div
            data-test="sandbox-bar"
            className="flex min-h-10 shrink-0 items-center justify-between gap-3 border-b border-sidebar-border/50 px-4 py-1 text-sm text-muted-foreground"
        >
            {left === null ? (
                <Form {...startDemo.form()} className="min-w-0">
                    {({ processing }) => (
                        <p>
                            This sandbox has expired.{' '}
                            <button
                                type="submit"
                                disabled={processing}
                                className="text-foreground underline underline-offset-4"
                                data-test="start-new-sandbox"
                            >
                                Start a new one
                            </button>
                        </p>
                    )}
                </Form>
            ) : (
                <p className="min-w-0 truncate">
                    <span className="hidden sm:inline">
                        Your demo sandbox expires in{' '}
                    </span>
                    <span className="sm:hidden">Expires in </span>
                    <span className="text-foreground tabular-nums">{left}</span>
                </p>
            )}

            {left !== null && demo.personas.length > 1 && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="-me-2 shrink-0 font-normal text-muted-foreground"
                            data-test="view-as"
                        >
                            View as
                            <span className="text-foreground">
                                {current?.name ?? 'You'}
                            </span>
                            <ChevronDown />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-64">
                        <DropdownMenuLabel className="font-normal text-muted-foreground">
                            Sign in as another person in your sandbox
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {demo.personas.map((persona) => (
                            <DropdownMenuItem
                                key={persona.id}
                                onSelect={() => {
                                    if (!persona.current) {
                                        switchTo(persona);
                                    }
                                }}
                                className="items-start"
                            >
                                <div className="min-w-0 flex-1">
                                    <p>{persona.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {roles(persona)}
                                    </p>
                                </div>
                                {persona.current && (
                                    <Check className="mt-0.5" />
                                )}
                            </DropdownMenuItem>
                        ))}
                        {demo.resume_url && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => {
                                        void copy(demo.resume_url ?? '').then(
                                            (copied) =>
                                                copied
                                                    ? toast.success(
                                                          'Link copied. Open it in any browser to come back to this sandbox.',
                                                      )
                                                    : toast.error(
                                                          'Could not copy the link. Find it on the AI clients page.',
                                                      ),
                                        );
                                    }}
                                    data-test="copy-resume-link"
                                >
                                    <LinkIcon />
                                    Copy a link back to this sandbox
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
}

/** Sign in as the persona, dropping every prefetched page of the person signed in now. */
function switchTo(persona: DemoPersona): void {
    router.flushAll();
    router.post(viewAs.url(persona.id));
}

/** "Acme Owner", or "Acme Member, Globex Member". */
function roles(persona: DemoPersona): string {
    return persona.roles.map((role) => `${role.team} ${role.role}`).join(', ');
}
