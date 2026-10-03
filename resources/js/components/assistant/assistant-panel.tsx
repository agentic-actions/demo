import { usePage } from '@inertiajs/react';
import { Sparkles, X } from 'lucide-react';
import { lazy, Suspense, useState } from 'react';
import AssistantSkeleton from '@/components/assistant/assistant-skeleton';
import { Button } from '@/components/ui/button';
import { useAssistantPanel } from '@/hooks/use-assistant-panel';
import { cn } from '@/lib/utils';

// The chat, with the AI SDK and the Markdown renderer, loads the first time the panel opens, not with every page.
const AssistantChat = lazy(
    () => import('@/components/assistant/assistant-chat'),
);

/**
 * The board assistant, on the right of every team page: full screen on a phone, a sheet over the page on a tablet or a
 * small laptop, and docked beside the page from 1280px, where the board keeps enough width for its three columns. It
 * lives in the persistent app layout, so a chat survives Inertia visits, and once opened it stays mounted while
 * closed: a turn keeps streaming, and the board keeps following the agent's writes. It talks to BoardAssistant through
 * POST /{team}/assistant.
 */
export default function AssistantPanel() {
    const { auth, currentTeam } = usePage().props;
    const { open, setOpen } = useAssistantPanel();
    const [opened, setOpened] = useState(false);

    if (open && !opened) {
        setOpened(true);
    }

    if (!currentTeam || !opened) {
        return null;
    }

    return (
        <aside
            aria-label="Assistant"
            className={cn(
                'fixed inset-0 z-40 flex flex-col bg-background sm:start-auto sm:w-96 sm:border-s sm:shadow-xl xl:sticky xl:inset-auto xl:top-0 xl:h-svh xl:shrink-0 xl:self-start xl:shadow-none',
                !open && 'hidden',
            )}
        >
            <header className="flex h-16 shrink-0 items-center justify-between gap-2 border-b px-4">
                <div className="flex min-w-0 items-center gap-2">
                    <Sparkles className="size-4 shrink-0" />
                    <h2 className="truncate text-sm font-semibold">
                        Assistant · {currentTeam.name}
                    </h2>
                </div>
                <Button
                    size="icon"
                    variant="ghost"
                    onClick={() => setOpen(false)}
                    aria-label="Close the assistant"
                    title="Close"
                >
                    <X />
                </Button>
            </header>

            <Suspense fallback={<AssistantSkeleton />}>
                {/* A new chat for another person or team: "View as" signs in someone else on the same page. */}
                <AssistantChat
                    key={`${auth.user.id}:${currentTeam.slug}`}
                    team={currentTeam.slug}
                    teamName={currentTeam.name}
                />
            </Suspense>
        </aside>
    );
}
