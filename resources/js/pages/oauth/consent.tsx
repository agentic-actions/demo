import { Head } from '@inertiajs/react';
import { ArrowUpRight, Check, ShieldAlert, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';

/** A form Passport's approve or deny route takes: its URL, method and hidden fields. */
type Answer = {
    url: string;
    method: string;
    fields: Record<string, string>;
};

/** What AgenticActions\OAuth\Consent::toArray() hands the page. */
type Props = {
    app: string;
    client: string;
    redirect: { host: string; local: boolean };
    tenant: string | null;
    abilities: string[];
    person: string;
    approve: Answer;
    deny: Answer;
};

/**
 * The OAuth consent screen a remote MCP client (a Claude custom connector, ChatGPT) sends the person to: which app
 * asks, what it may do, in which team, and where the answer goes. Allow and Deny are plain HTML forms posting to
 * Passport's routes, never an Inertia visit: the answer redirects to the client, such as claude.ai, which an XHR
 * cannot follow.
 */
export default function Consent({
    client,
    redirect,
    tenant,
    abilities,
    person,
    approve,
    deny,
}: Props) {
    return (
        <>
            <Head title={`Connect ${client}`} />

            <div className="space-y-6">
                <div className="space-y-3 rounded-lg border p-4">
                    <p className="text-sm font-medium">
                        {client} will be able to:
                    </p>
                    <ul className="space-y-2" data-test="consent-abilities">
                        {abilities.map((ability) => (
                            <li
                                key={ability}
                                className="flex items-start gap-2 text-sm"
                            >
                                <Check className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                {ability}
                            </li>
                        ))}
                    </ul>
                </div>

                <ul className="space-y-3 text-sm">
                    {tenant !== null && (
                        <li
                            className="flex items-start gap-2"
                            data-test="consent-team"
                        >
                            <Users className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <span>
                                Only in the <strong>{tenant}</strong> team, with
                                your role there.
                            </span>
                        </li>
                    )}
                    <li
                        className="flex items-start gap-2"
                        data-test="consent-redirect"
                    >
                        <ArrowUpRight className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <span>
                            {redirect.local
                                ? 'After you answer, you go back to an app on this device '
                                : 'After you answer, you go to '}
                            <strong className="break-all">
                                {redirect.local
                                    ? `(${redirect.host})`
                                    : redirect.host}
                            </strong>
                            .
                        </span>
                    </li>
                </ul>

                <p className="flex items-start gap-2 border-t pt-4 text-sm text-muted-foreground">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                    Continue only if you started connecting {client} yourself,
                    just now.
                </p>

                <div className="grid grid-cols-2 gap-3">
                    <AnswerForm answer={deny} variant="outline">
                        Deny
                    </AnswerForm>
                    <AnswerForm answer={approve} variant="default">
                        Allow
                    </AnswerForm>
                </div>

                <p className="text-center text-xs text-muted-foreground">
                    Signed in as {person}
                </p>
            </div>
        </>
    );
}

Consent.layout = (props: Props) => ({
    title: `Connect ${props.client} to ${props.app}?`,
    description: `${props.client} asks to act for you in ${props.app}.`,
});

/** One answer as a native form: the browser follows Passport's redirect to the client. */
function AnswerForm({
    answer,
    variant,
    children,
}: {
    answer: Answer;
    variant: 'default' | 'outline';
    children: React.ReactNode;
}) {
    return (
        <form method={answer.method} action={answer.url}>
            {Object.entries(answer.fields).map(([name, value]) => (
                <input key={name} type="hidden" name={name} value={value} />
            ))}
            <Button type="submit" variant={variant} className="w-full">
                {children}
            </Button>
        </form>
    );
}
