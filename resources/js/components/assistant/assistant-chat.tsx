import { Chat, useChat } from '@ai-sdk/react';
import {
    actionRows,
    approvalCard,
    elicitation,
    messageSegments,
    viewsOf,
    type ElicitResult,
    type WaitingApproval,
    type WaitingElicitation,
} from '@agentic-actions/client';
import {
    actionsChat,
    answerElicitation,
    refusalMessage,
    turnOutcome,
    type ActionMessage,
    type ActionsChatOptions,
    type TurnOutcome,
} from '@agentic-actions/client/ai-sdk';
import { ActionActivity, ApprovalCard } from '@agentic-actions/client/react';
import { Link, usePage } from '@inertiajs/react';
import { ArrowUp, RefreshCw, Sparkles, Square } from 'lucide-react';
import {
    Fragment,
    useEffect,
    useRef,
    useState,
    type FormEvent,
    type KeyboardEvent,
} from 'react';
import AssistantForm from '@/components/assistant/assistant-form';
import AssistantMarkdown from '@/components/assistant/assistant-markdown';
import AssistantSkeleton from '@/components/assistant/assistant-skeleton';
import AssistantTable from '@/components/assistant/assistant-table';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { assistant } from '@/routes';
import { transcript as transcriptRoute } from '@/routes/assistant';
import { index as tokens } from '@/routes/teams/tokens';
import { useAssistantPanel } from '@/hooks/use-assistant-panel';

/** The prompts the demo planner follows; a real model takes them too. */
const suggestions = [
    "What's overdue?",
    'Tasks by status',
    'Who has the most tasks?',
    'Tasks completed per day',
    'Tasks per project',
    'Tasks completed per week',
    'Add three launch tasks for Marcus',
    'Add a task for Marcus to review the pricing page',
    "Move 'Design the empty states' to done",
    "Delete 'Order new office chairs'",
];

/** The conversation so far, whether replies are scripted, and the longest message the endpoint reads. */
type Transcript = {
    messages: ActionMessage[];
    demo: boolean;
    max_length: number;
};

/** A turn the panel stopped following before it finished. The server still finishes it and stores the reply. */
type Unfinished = Extract<TurnOutcome, 'stopped' | 'interrupted'>;

const unfinishedNotes: Record<Unfinished, string> = {
    stopped:
        'You stopped following this reply. The assistant still finishes it, so reload to see what it did.',
    interrupted:
        'The connection dropped before this reply finished. Reload to see how it ended.',
};

type Loaded =
    | { status: 'loading' }
    | { status: 'failed' }
    | ({ status: 'ready' } & Transcript);

/**
 * Loads this person's conversation in this team (the transcript endpoint), then starts the chat with it, so a reload
 * shows the words again. Rows are live only: a reloaded chat has no rows.
 */
export default function AssistantChat({
    team,
    teamName,
}: {
    team: string;
    teamName: string;
}) {
    const [loaded, setLoaded] = useState<Loaded>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const controller = new AbortController();

        fetch(transcriptRoute(team).url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                const body = (await response.json()) as Transcript;

                setLoaded({ status: 'ready', ...body });
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setLoaded({ status: 'failed' });
                }
            });

        return () => controller.abort();
    }, [team, attempt]);

    if (loaded.status === 'loading') {
        return <AssistantSkeleton />;
    }

    if (loaded.status === 'failed') {
        return (
            <div className="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center text-sm">
                <p className="text-muted-foreground">
                    The conversation did not load.
                </p>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        setLoaded({ status: 'loading' });
                        setAttempt((count) => count + 1);
                    }}
                >
                    <RefreshCw />
                    Try again
                </Button>
            </div>
        );
    }

    return (
        <ChatThread
            team={team}
            teamName={teamName}
            transcript={loaded.messages}
            demo={loaded.demo}
            maxLength={loaded.max_length}
        />
    );
}

function ChatThread({
    team,
    teamName,
    transcript,
    demo,
    maxLength,
}: {
    team: string;
    teamName: string;
    transcript: ActionMessage[];
    demo: boolean;
    maxLength: number;
}) {
    const { url, component, props } = usePage();
    const { setOpen } = useAssistantPanel();
    const currentPage = useRef({ url, component });

    useEffect(() => {
        currentPage.current = { url, component };
    }, [url, component]);

    const [unfinished, setUnfinished] = useState<Record<string, Unfinished>>(
        {},
    );

    // One Chat per mounted panel, never at module scope: under SSR a module-level Chat would be shared by requests. Its
    // options are kept too: answerElicitation() checks a form's answer against the same endpoint, with the same body.
    const [{ chat, options }] = useState(() => {
        const options: ActionsChatOptions = {
            api: assistant(team).url,
            messages: transcript,
            // The page goes with every message, for #[WithPageContext]. Read at send time.
            body: () => ({ page: currentPage.current }),
            onFinish: (event) => {
                const outcome = turnOutcome(event);

                if (outcome === 'stopped' || outcome === 'interrupted') {
                    setUnfinished((current) => ({
                        ...current,
                        [event.message.id]: outcome,
                    }));
                }
            },
        };

        return { chat: new Chat<ActionMessage>(actionsChat(options)), options };
    });

    const {
        messages,
        sendMessage,
        status,
        error,
        stop,
        clearError,
        addToolApprovalResponse,
    } = useChat({ chat });

    const [input, setInput] = useState('');
    const busy = status === 'submitted' || status === 'streaming';
    const last = messages.at(-1);
    // A delete the newest reply waits on: the card the server built, until the person answers it. actionsChat() then
    // sends the answer by itself, and the reply carries on in the same message.
    const approval = approvalCard(last);
    // A task the model left details out of: the form the server built, until the person answers it. An answer the
    // action's rules refuse comes back as errors under the fields; one they accept goes out like a confirmation.
    const form = elicitation(last);
    const thinking =
        status === 'submitted' || (status === 'streaming' && isWaiting(last));

    const scroller = useRef<HTMLDivElement>(null);

    useEffect(() => {
        scroller.current?.scrollTo({ top: scroller.current.scrollHeight });
    }, [messages, thinking, error, unfinished]);

    const send = (text: string) => {
        const words = text.trim();

        if (words === '' || busy) {
            return;
        }

        if (error) {
            clearError();
        }

        void sendMessage({ text: words });
        setInput('');
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(input);
    };

    const sendOnEnter = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.nativeEvent.isComposing
        ) {
            event.preventDefault();
            send(input);
        }
    };

    return (
        <>
            {demo && props.demo.hosted && (
                <p className="border-b bg-amber-50 px-4 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    <span className="font-medium">Scripted demo.</span> Replies
                    follow a script, and the actions, permissions and cards are
                    real. To use a real model on this board, connect Claude over
                    MCP from the{' '}
                    <Link
                        href={tokens(team)}
                        onClick={() => closeOverPage(setOpen)}
                        className="underline underline-offset-2"
                    >
                        AI clients page
                    </Link>
                    .
                </p>
            )}

            {demo && !props.demo.hosted && (
                <p className="border-b bg-amber-50 px-4 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    <span className="font-medium">Demo mode</span> — scripted
                    replies, add OPENROUTER_API_KEY for a real model
                </p>
            )}

            <div
                ref={scroller}
                className="flex flex-1 flex-col gap-4 overflow-y-auto p-4"
            >
                {messages.length === 0 && (
                    <div className="m-auto flex max-w-64 flex-col items-center gap-2 text-center text-sm text-muted-foreground">
                        <Sparkles className="size-5" />
                        <p className="font-medium text-foreground">
                            Ask about the {teamName} board
                        </p>
                        <p>
                            The assistant runs the board's own actions, as you,
                            with your role. Before it deletes a task, it asks
                            you to confirm, and when a new task lacks a due date
                            or a priority, it asks you for them. Ask about the
                            board and it shows a table and a chart.
                        </p>
                    </div>
                )}

                {messages.map((message) =>
                    message.role === 'user' ? (
                        <UserMessage key={message.id} message={message} />
                    ) : (
                        <AssistantMessage
                            key={message.id}
                            team={team}
                            message={message}
                            live={busy && message === last}
                            unfinished={unfinished[message.id]}
                            approval={message === last ? approval : null}
                            onAnswer={(id, approved) =>
                                void addToolApprovalResponse({ id, approved })
                            }
                            form={message === last ? form : null}
                            onFormAnswer={(id, result) =>
                                answerElicitation(chat, options, id, result)
                            }
                        />
                    ),
                )}

                {thinking && (
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Spinner className="size-3" />
                        Thinking…
                    </p>
                )}

                {error && (
                    <p
                        role="alert"
                        className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                    >
                        {refusalMessage(error) ?? error.message}
                    </p>
                )}
            </div>

            <div className="flex flex-col gap-2 border-t p-3">
                <div className="flex flex-wrap gap-1.5">
                    {suggestions.map((suggestion) => (
                        <button
                            key={suggestion}
                            type="button"
                            disabled={busy}
                            onClick={() => send(suggestion)}
                            className="rounded-full border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50"
                        >
                            {suggestion}
                        </button>
                    ))}
                </div>

                <form onSubmit={submit} className="flex items-end gap-2">
                    <Textarea
                        dir="auto"
                        value={input}
                        onChange={(event) => setInput(event.target.value)}
                        onKeyDown={sendOnEnter}
                        placeholder="Ask the assistant…"
                        aria-label="Message the assistant"
                        maxLength={maxLength}
                        rows={1}
                        className="max-h-40 min-h-9 resize-none"
                    />
                    {busy ? (
                        <Button
                            type="button"
                            size="icon"
                            variant="outline"
                            onClick={() => void stop()}
                            aria-label="Stop"
                            title="Stop"
                        >
                            <Square className="fill-current" />
                        </Button>
                    ) : (
                        <Button
                            type="submit"
                            size="icon"
                            disabled={input.trim() === ''}
                            aria-label="Send"
                            title="Send"
                        >
                            <ArrowUp />
                        </Button>
                    )}
                </form>
            </div>
        </>
    );
}

function UserMessage({ message }: { message: ActionMessage }) {
    return (
        <div className="ms-8 self-end rounded-2xl rounded-ee-sm bg-primary px-3 py-2 text-sm text-primary-foreground">
            <Words message={message} />
        </div>
    );
}

/**
 * The reply as it happened: a model often says what it is about to do, runs its tools, then reports, so rows sit
 * between the words that came before and after them. Only the reply the chat is streaming is live. Every other one is
 * settled, so after Stop, an error or a cut stream no row stays running. Segments only grow at the end, so their
 * position is a stable key. A delete waiting for the person shows last, as the package's card: the sentence and the
 * task's rows, both built on the server, with Confirm and Decline. So does a task waiting for its missing details, as
 * the package's form, keyed by its call so each form keeps its own state. A table the reply showed sits after the rows,
 * with the chart its rows call for; a reloaded reply has no rows, so its tables come first.
 */
function AssistantMessage({
    team,
    message,
    live,
    unfinished,
    approval,
    onAnswer,
    form,
    onFormAnswer,
}: {
    team: string;
    message: ActionMessage;
    live: boolean;
    unfinished: Unfinished | undefined;
    approval: WaitingApproval | null;
    onAnswer: (id: string, approved: boolean) => void;
    form: WaitingElicitation | null;
    onFormAnswer: (
        id: string,
        result: ElicitResult,
    ) => Promise<Record<string, string[]> | null>;
}) {
    const segments = messageSegments(message, { settled: !live });
    // The tables follow the rows of the calls that showed them. A reloaded reply has no rows: its tables come first.
    const tables = viewsOf(message).map((view) => (
        <AssistantTable key={view.id} view={view} team={team} />
    ));
    let afterRows = -1;

    segments.forEach((segment, position) => {
        if (segment.type === 'rows') {
            afterRows = position;
        }
    });

    return (
        <div className="me-8 flex flex-col gap-2 text-sm">
            {afterRows === -1 && tables}
            {segments.map((segment, position) => (
                <Fragment key={position}>
                    {segment.type === 'rows' ? (
                        <ActionActivity rows={segment.rows} />
                    ) : (
                        <AssistantMarkdown
                            text={segment.text}
                            streaming={live}
                        />
                    )}
                    {position === afterRows && tables}
                </Fragment>
            ))}
            {approval && (
                <ApprovalCard
                    approval={approval}
                    onAnswer={(approved) => onAnswer(approval.id, approved)}
                />
            )}
            {form && (
                <AssistantForm
                    key={form.id}
                    form={form}
                    onAnswer={(result) => onFormAnswer(form.id, result)}
                />
            )}
            {unfinished && (
                <p className="text-xs text-muted-foreground">
                    {unfinishedNotes[unfinished]}
                </p>
            )}
        </div>
    );
}

/**
 * Whether the model is working with nothing on screen to show for it: a step has started and written nothing yet, or
 * every row so far has finished and no words are coming.
 */
function isWaiting(message: ActionMessage | undefined): boolean {
    if (message?.role !== 'assistant') {
        return true;
    }

    const newest = message.parts.at(-1);

    if (newest === undefined || newest.type === 'step-start') {
        return true;
    }

    if (newest.type === 'text') {
        return false;
    }

    return !actionRows(message).some((row) => row.status === 'running');
}

/**
 * The person's own words, as plain text: never Markdown. dir="auto" lets Arabic words read right to left inside an
 * English page.
 */
function Words({ message }: { message: ActionMessage }) {
    return message.parts.map((part, index) =>
        part.type === 'text' && part.text !== '' ? (
            <p
                key={index}
                dir="auto"
                className="break-words whitespace-pre-wrap"
            >
                {part.text}
            </p>
        ) : null,
    );
}

/**
 * Below the xl breakpoint the panel covers the page, so a link that opens another page closes it first; from xl up it
 * sits beside the page and stays open.
 */
function closeOverPage(setOpen: (open: boolean) => void): void {
    if (!window.matchMedia('(min-width: 80rem)').matches) {
        setOpen(false);
    }
}
