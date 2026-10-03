import { useActionSync } from '@agentic-actions/client/react';
import { _changes } from '@/routes/actions';

/**
 * How often the board asks the change feed what changed, in milliseconds. The package's default is 15 000; the demo
 * polls faster so a write from an MCP client shows within a few seconds.
 */
const FEED_INTERVAL_MS = 5_000;

/**
 * The board's one sync. It reloads the props that writes made stale: the assistant's, from its done rows, and every
 * other write in this team, from the change feed (an MCP client, a queued job, a teammate, another tab). Only the
 * touched props reload, never the page. While an edit dialog has unsaved changes the reload waits, and this note says
 * so. The feed URL is read at mount, so the board mounts it with key={team}.
 */
export default function BoardSync({ team }: { team: string }) {
    const { waiting } = useActionSync({
        feed: { url: _changes(team).url, interval: FEED_INTERVAL_MS },
    });

    if (!waiting) {
        return null;
    }

    return (
        <p
            role="status"
            className="rounded-lg border bg-muted/50 px-3 py-2 text-sm"
        >
            The board changed. It catches up when you close the task you are
            editing.
        </p>
    );
}
