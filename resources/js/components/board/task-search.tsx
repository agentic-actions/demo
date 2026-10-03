import { useAction } from '@agentic-actions/client/react';
import { Search, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { searchTasks } from '@/agentic/actions';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { statusLabel } from '@/lib/board';
import type { BoardTask } from '@/types';

/**
 * search-tasks is a Read: it answers JSON on every route, so the page asks for it with useAction() and shows the
 * typed output. The assistant finds task numbers with the same action.
 */
export default function TaskSearch({
    team,
    onOpen,
}: {
    team: string;
    onOpen: (task: BoardTask) => void;
}) {
    const form = useAction(searchTasks({ current_team: team }), { query: '' });
    const [results, setResults] = useState<BoardTask[] | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        const output = await form.run();

        setResults(output?.tasks ?? null);
    };

    // Not form.reset(): after a successful run Inertia's useHttp makes the submitted data the new defaults, so reset()
    // would put the last query back.
    const clear = () => {
        form.setData('query', '');
        form.clearErrors();
        setResults(null);
    };

    return (
        <div className="relative w-full sm:w-72">
            <form onSubmit={submit} className="relative">
                <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                <Input
                    value={form.data.query}
                    onChange={(event) =>
                        form.setData('query', event.target.value)
                    }
                    placeholder="Search tasks and press Enter"
                    className="h-9 pl-8"
                    aria-label="Search tasks"
                />
                {results !== null && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="absolute top-0.5 right-0.5 size-8"
                        onClick={clear}
                        aria-label="Clear search"
                    >
                        <X />
                    </Button>
                )}
            </form>
            <InputError
                message={form.errors.query ?? form.refusal ?? undefined}
                className="mt-1"
            />

            {results !== null && (
                <div className="absolute top-11 right-0 left-0 z-20 max-h-80 overflow-y-auto rounded-lg border bg-popover p-1 text-popover-foreground shadow-md">
                    {results.length === 0 ? (
                        <p className="px-2 py-3 text-sm text-muted-foreground">
                            No task matches.
                        </p>
                    ) : (
                        results.map((task) => (
                            <button
                                key={task.id}
                                type="button"
                                onClick={() => {
                                    onOpen(task);
                                    clear();
                                }}
                                className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent"
                            >
                                <span className="min-w-0 truncate">
                                    <span className="text-muted-foreground">
                                        #{task.id}
                                    </span>{' '}
                                    {task.title}
                                </span>
                                <Badge variant="outline" className="shrink-0">
                                    {statusLabel(task.status)}
                                </Badge>
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
