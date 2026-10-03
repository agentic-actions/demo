import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { board } from '@/routes';

type Props = {
    message: string;
};

/**
 * A team page someone opened without being in the team, such as Gus on Acme's board: the reason, in the app's own
 * layout, with a way back to their board. On the hosted demo, "View as" in the sandbox bar switches to someone who is.
 */
export default function Forbidden({ message }: Props) {
    const { currentTeam, demo } = usePage().props;

    return (
        <>
            <Head title="Not in this team" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div
                    className="max-w-xl rounded-xl border p-6"
                    data-test="forbidden"
                >
                    <h1 className="text-xl font-semibold tracking-tight">
                        Not in this team
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {message}
                        {demo.hosted &&
                            ' To see it, use “View as” above to switch to someone who is.'}
                    </p>
                    {currentTeam && (
                        <Button asChild className="mt-4">
                            <Link href={board(currentTeam.slug)}>
                                Open your board
                                <ArrowRight />
                            </Link>
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}
