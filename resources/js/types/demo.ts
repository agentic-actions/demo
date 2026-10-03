/** One person of a hosted demo sandbox, for "View as": their roles in the sample teams, and whether they are signed in. */
export type DemoPersona = {
    id: number;
    name: string;
    roles: { team: string; role: string }[];
    current: boolean;
};

/**
 * The hosted demo, shared with every page. `hosted` is on at demo.agentic-actions.com; the rest is set only for someone
 * signed in to a sandbox: when it ends, the signed link back into it, and its people, the visitor first.
 */
export type Demo = {
    hosted: boolean;
    expires_at: string | null;
    resume_url: string | null;
    personas: DemoPersona[];
};
