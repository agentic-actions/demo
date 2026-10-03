import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Button } from '@/components/ui/button';
import { board, dashboard } from '@/routes';
import type { DashboardInvitation } from '@/types';

type Props = {
    pendingInvitations?: DashboardInvitation[];
};

/**
 * Where signing in lands. The demo happens on the board, so this page only points there.
 */
export default function Dashboard({ pendingInvitations = [] }: Props) {
    const { currentTeam } = usePage().props;
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );

    return (
        <>
            <Head title="Dashboard" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="max-w-xl rounded-xl border p-6">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {currentTeam?.name}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        The demo happens on the board. Every change you make
                        there runs an Agentic Action, the same class the CLI and
                        the assistant call.
                    </p>
                    {currentTeam && (
                        <Button asChild className="mt-4">
                            <Link href={board(currentTeam.slug)}>
                                Open the board
                                <ArrowRight />
                            </Link>
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentTeam ? dashboard(props.currentTeam.slug) : '/',
        },
    ],
});
