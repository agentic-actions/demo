import { Head, Link, usePage } from '@inertiajs/react';
import { TryTheDemo } from '@/components/try-the-demo';
import { Button } from '@/components/ui/button';
import { links } from '@/lib/demo';
import { board, login, register } from '@/routes';

const tries: { title: string; hosted: string; local: string }[] = [
    {
        title: 'The board',
        hosted: 'Add, move and finish tasks. Each change runs one action.',
        local: 'Add, move and finish tasks. Each change runs one action.',
    },
    {
        title: 'The copilot',
        hosted: 'Ask what is overdue, or add tasks for Marcus. Its replies follow a script here, and the actions it runs are real.',
        local: 'Ask what is overdue, or add tasks for Marcus. It runs the same actions, with your role.',
    },
    {
        title: 'Roles',
        hosted: 'View the board as Marcus, a member, Vera, a viewer, or Gus, who is not in Acme at all.',
        local: 'Sign in as Vera, a viewer, and the board and the copilot refuse her changes.',
    },
    {
        title: 'AI clients',
        hosted: 'Connect Claude Code, Cursor or Claude.ai over MCP, and drive the same board with your own model.',
        local: 'Connect Claude Code, Cursor or Claude Desktop over MCP, and drive the same board.',
    },
];

/**
 * The demo's front page: one line on what Agentic Actions is, the way in, and where to read more. On the hosted demo
 * the way in is "Try the demo", which makes the visitor a sandbox of their own; elsewhere it is the seeded users.
 */
export default function Welcome() {
    const { auth, currentTeam, demo } = usePage().props;

    return (
        <>
            <Head title="Agentic Actions demo" />

            <div className="flex min-h-svh flex-col bg-background text-foreground">
                <header className="mx-auto flex h-16 w-full max-w-3xl items-center justify-between gap-4 px-6">
                    <span className="text-sm">
                        Agentic Actions{' '}
                        <span className="text-muted-foreground">demo</span>
                    </span>
                    <nav className="flex items-center gap-5 text-sm text-muted-foreground">
                        <a href={links.docs} className="hover:text-foreground">
                            Docs
                        </a>
                        <a
                            href={links.package}
                            className="hover:text-foreground"
                        >
                            GitHub
                        </a>
                    </nav>
                </header>

                <main className="mx-auto w-full max-w-3xl flex-1 px-6 pt-16 pb-20 sm:pt-28">
                    <h1 className="max-w-2xl text-3xl leading-tight font-normal tracking-tight text-balance sm:text-4xl">
                        Agentic Actions lets you write a Laravel action once,
                        and run it from your forms, your API, an AI copilot and
                        MCP clients.
                    </h1>
                    <p className="mt-4 max-w-xl text-muted-foreground">
                        This demo is a team task board built with it. Each
                        surface checks the same rules and the same roles.
                    </p>

                    <div className="mt-10">
                        {auth.user ? (
                            <Button asChild size="lg">
                                <Link
                                    href={
                                        currentTeam
                                            ? board(currentTeam.slug)
                                            : '/'
                                    }
                                >
                                    Open your board
                                </Link>
                            </Button>
                        ) : demo.hosted ? (
                            <TryTheDemo />
                        ) : (
                            <div className="space-y-4">
                                <div className="flex flex-wrap gap-3">
                                    <Button asChild size="lg">
                                        <Link href={login()}>Log in</Link>
                                    </Button>
                                    <Button asChild size="lg" variant="outline">
                                        <Link href={register()}>Register</Link>
                                    </Button>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    The seeded users, such as owner@example.com,
                                    all use the password “password”.
                                </p>
                            </div>
                        )}
                    </div>

                    <section className="mt-20">
                        <h2 className="text-sm text-muted-foreground">
                            What to try
                        </h2>
                        <dl className="mt-4 grid gap-x-10 gap-y-6 border-t pt-6 sm:grid-cols-2">
                            {tries.map((item) => (
                                <div key={item.title}>
                                    <dt>{item.title}</dt>
                                    <dd className="mt-1 text-sm text-muted-foreground">
                                        {demo.hosted ? item.hosted : item.local}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                </main>

                <footer className="mx-auto w-full max-w-3xl px-6 pb-10">
                    <nav className="flex flex-wrap gap-x-6 gap-y-2 border-t pt-6 text-sm text-muted-foreground">
                        <a href={links.docs} className="hover:text-foreground">
                            Documentation
                        </a>
                        <a
                            href={links.package}
                            className="hover:text-foreground"
                        >
                            The package on GitHub
                        </a>
                        <a
                            href={links.source}
                            className="hover:text-foreground"
                        >
                            This demo's source
                        </a>
                    </nav>
                </footer>
            </div>
        </>
    );
}
