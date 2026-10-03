import { Form, Head, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useClipboard } from '@/hooks/use-clipboard';
import { edit, index } from '@/routes/teams';
import { destroy as disconnect } from '@/routes/teams/connections';
import { destroy, index as tokensIndex, store } from '@/routes/teams/tokens';
import type {
    NewTeamToken,
    TeamConnection,
    TeamToken,
    TokenAccess,
} from '@/types';

type Props = {
    team: { name: string; slug: string };
    mcpUrl: string;
    connectorUrl: string | null;
    connections: TeamConnection[];
    tokens: TeamToken[];
};

const accessOptions: { value: TokenAccess; label: string; hint: string }[] = [
    {
        value: 'read',
        label: 'Read only',
        hint: 'List, search and summarize tasks.',
    },
    {
        value: 'write',
        label: 'Read and write',
        hint: 'Also create and change tasks and projects.',
    },
];

/**
 * The team's "AI clients" page. A token made here is bound to this team and names its abilities (read, or read and
 * write), so an MCP client that sends it reaches this team's board with the person's own role, and nothing else. The
 * plain text arrives once, as flash data, which Inertia keeps out of the browser history. Apps that sign in with OAuth
 * instead (a Claude custom connector, ChatGPT) are listed under "Connected apps" once the person approved them.
 */
export default function TeamTokens({
    team,
    mcpUrl,
    connectorUrl,
    connections,
    tokens,
}: Props) {
    const { flash, props } = usePage();
    const created = flash.token as NewTeamToken | undefined;
    const { demo } = props;
    const [access, setAccess] = useState<TokenAccess>('write');
    const clientName = `agentic-demo-${team.slug}`;

    return (
        <>
            <Head title={`AI clients · ${team.name}`} />

            <h1 className="sr-only">AI clients for {team.name}</h1>

            <div className="flex flex-col space-y-10">
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="AI clients"
                        description={`Claude Code, Cursor and Claude Desktop reach the ${team.name} board over MCP with a token; Claude.ai and ChatGPT sign in and ask you to approve. A client does what your role allows in ${team.name}, and never more. Nobody can delete tasks over MCP.`}
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="mcp-url">MCP URL</Label>
                        <div className="flex gap-2">
                            <Input
                                id="mcp-url"
                                value={mcpUrl}
                                readOnly
                                className="font-mono text-xs"
                            />
                            <CopyButton text={mcpUrl} label="Copy the URL" />
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Connected apps"
                        description={`Apps you connected to ${team.name} by signing in and approving, such as a Claude custom connector. Each reaches only this team, with the access you approved.`}
                    />

                    {connectorUrl ? (
                        <div className="grid gap-2">
                            <Label htmlFor="connector-url">Connector URL</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="connector-url"
                                    value={connectorUrl}
                                    readOnly
                                    className="font-mono text-xs"
                                />
                                <CopyButton
                                    text={connectorUrl}
                                    label="Copy the connector URL"
                                />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                In Claude, open Settings, Connectors, Add custom
                                connector, and paste it. Claude sends you here
                                to sign in and approve.
                            </p>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Claude.ai and ChatGPT reach the board from the
                            internet: once this app has a public HTTPS address,
                            the URL to paste into them shows here.
                        </p>
                    )}

                    {demo.resume_url && (
                        <div className="grid gap-2" data-test="resume-link">
                            <Label htmlFor="resume-url">
                                Sign in from another browser
                            </Label>
                            <div className="flex gap-2">
                                <Input
                                    id="resume-url"
                                    value={demo.resume_url}
                                    readOnly
                                    className="font-mono text-xs"
                                />
                                <CopyButton
                                    text={demo.resume_url}
                                    label="Copy the sign-in link"
                                />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Claude may open another browser, or the one on
                                your phone, for you to approve. If that browser
                                is not signed in here, open this link in it
                                first. The link works until your sandbox
                                expires.
                            </p>
                            <p className="text-sm text-muted-foreground">
                                When the sandbox expires, its board is deleted
                                and a connector to it stops working. To try
                                again, start a new sandbox and add the connector
                                with its URL.
                            </p>
                        </div>
                    )}

                    {connections.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No apps connected yet.
                        </p>
                    ) : (
                        <div className="space-y-3">
                            {connections.map((connection) => (
                                <div
                                    key={connection.id}
                                    data-test="connection-row"
                                    className="flex items-center justify-between gap-4 rounded-lg border p-4"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate font-medium">
                                                {connection.client}
                                            </span>
                                            <Badge variant="secondary">
                                                {connection.access_label}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {`Returns to ${connection.sends_to}`}
                                            {connection.connected_at &&
                                                ` · Connected ${ago(connection.connected_at)}`}
                                        </p>
                                    </div>

                                    <Form
                                        {...disconnect.form([
                                            team.slug,
                                            connection.id,
                                        ])}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Revoke
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {created && (
                    <div
                        data-test="new-token"
                        className="space-y-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950/40"
                    >
                        <p className="text-sm font-medium">
                            “{created.name}” is ready. Copy the token now: it is
                            not shown again.
                        </p>
                        <div className="flex gap-2">
                            <Input
                                aria-label="New token"
                                value={created.plain_text}
                                readOnly
                                className="bg-background font-mono text-xs"
                            />
                            <CopyButton
                                text={created.plain_text}
                                label="Copy the token"
                            />
                        </div>
                    </div>
                )}

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Create a token"
                        description={
                            demo.hosted
                                ? 'It stops working when your sandbox expires. Revoke it here at any time.'
                                : 'It lasts 90 days. Revoke it here at any time.'
                        }
                    />

                    <Form
                        {...store.form(team.slug)}
                        resetOnSuccess={['name']}
                        className="space-y-6"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        placeholder="Claude Code"
                                        maxLength={60}
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="access">Access</Label>
                                    <Select
                                        name="access"
                                        value={access}
                                        onValueChange={(value) =>
                                            setAccess(value as TokenAccess)
                                        }
                                    >
                                        <SelectTrigger
                                            id="access"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {accessOptions.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="text-sm text-muted-foreground">
                                        {
                                            accessOptions.find(
                                                (option) =>
                                                    option.value === access,
                                            )?.hint
                                        }
                                    </p>
                                    <InputError message={errors.access} />
                                </div>

                                <Button type="submit" disabled={processing}>
                                    <KeyRound />
                                    Create token
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Your tokens"
                        description={`Tokens you made for ${team.name}. Other members' tokens are theirs.`}
                    />

                    {tokens.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No tokens yet.
                        </p>
                    ) : (
                        <div className="space-y-3">
                            {tokens.map((token) => (
                                <div
                                    key={token.id}
                                    data-test="token-row"
                                    className="flex items-center justify-between gap-4 rounded-lg border p-4"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate font-medium">
                                                {token.name}
                                            </span>
                                            <Badge variant="secondary">
                                                {token.access_label}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {token.last_used_at
                                                ? `Last used ${ago(token.last_used_at)}`
                                                : 'Never used'}
                                            {token.expires_at &&
                                                ` · Expires ${day(token.expires_at)}`}
                                        </p>
                                    </div>

                                    <Form
                                        {...destroy.form([team.slug, token.id])}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Revoke
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Connect a client"
                        description={
                            created
                                ? 'Your new token is filled in below.'
                                : 'Replace YOUR_TOKEN with a token from above.'
                        }
                    />

                    {clientSetups(
                        clientName,
                        mcpUrl,
                        created?.plain_text ?? 'YOUR_TOKEN',
                    ).map((setup) => (
                        <div key={setup.client} className="space-y-2">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-sm font-medium">
                                    {setup.client}
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        · {setup.where}
                                    </span>
                                </p>
                                <CopyButton
                                    text={setup.text}
                                    label={`Copy the ${setup.client} setup`}
                                />
                            </div>
                            <pre className="overflow-x-auto rounded-lg bg-muted p-3 text-xs">
                                {setup.text}
                            </pre>
                            {setup.note && (
                                <p className="text-sm text-muted-foreground">
                                    {setup.note}
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

TeamTokens.layout = (props: { team: { name: string; slug: string } }) => ({
    breadcrumbs: [
        {
            title: 'Teams',
            href: index(),
        },
        {
            title: props.team.name,
            href: edit(props.team.slug),
        },
        {
            title: 'AI clients',
            href: tokensIndex(props.team.slug),
        },
    ],
});

/**
 * The three clients' setups for this URL and token. Claude Desktop starts a local bridge, mcp-remote, which sends the
 * header; it needs --allow-http for an http:// URL other than localhost.
 */
function clientSetups(
    name: string,
    url: string,
    token: string,
): { client: string; where: string; text: string; note?: string }[] {
    const bearer = `Bearer ${token}`;

    return [
        {
            client: 'Claude Code',
            where: 'run in a terminal',
            text: `claude mcp add --transport http ${name} ${url} \\\n  --header "Authorization: ${bearer}"`,
        },
        {
            client: 'Cursor',
            where: '.cursor/mcp.json or ~/.cursor/mcp.json',
            text: JSON.stringify(
                {
                    mcpServers: {
                        [name]: { url, headers: { Authorization: bearer } },
                    },
                },
                null,
                4,
            ),
        },
        {
            client: 'Claude Desktop',
            where: 'claude_desktop_config.json, then restart the app',
            text: JSON.stringify(
                {
                    mcpServers: {
                        [name]: {
                            command: 'npx',
                            args: [
                                '-y',
                                'mcp-remote',
                                url,
                                '--header',
                                'Authorization:${MCP_AUTH}',
                                ...(url.startsWith('http://')
                                    ? ['--allow-http']
                                    : []),
                            ],
                            env: { MCP_AUTH: bearer },
                        },
                    },
                },
                null,
                4,
            ),
            note: "Claude Desktop does not read your shell's PATH. If it cannot start npx, write npx's full path (from `which npx`) as command, and put that folder first on PATH in env.",
        },
    ];
}

function CopyButton({ text, label }: { text: string; label: string }) {
    const [copied, copy] = useClipboard();

    return (
        <Button
            type="button"
            variant="outline"
            size="icon"
            aria-label={label}
            title={label}
            onClick={() => void copy(text)}
        >
            {copied === text ? <Check /> : <Copy />}
        </Button>
    );
}

/** "5 minutes ago", "yesterday", or "just now" under a minute. */
function ago(iso: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

/** A date such as "Dec 24, 2026". */
function day(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
}
