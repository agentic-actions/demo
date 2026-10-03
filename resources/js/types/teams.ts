export type TeamRole = 'owner' | 'admin' | 'member' | 'viewer';

export type Team = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: TeamRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type TeamMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
};

export type TeamInvitation = {
    code: string;
    email: string;
    role: TeamRole;
    role_label: string;
    created_at: string;
};

export type TeamInvitationContext = {
    code: string;
    teamName: string;
};

export type DashboardInvitation = {
    code: string;
    inviterName: string;
    team: {
        name: string;
        slug: string;
    };
};

export type TeamPermissions = {
    canUpdateTeam: boolean;
    canDeleteTeam: boolean;
    canAddMember: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
    canCancelInvitation: boolean;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
};

export type TokenAccess = 'read' | 'write';

/** One of the signed-in person's MCP tokens for a team, from the "AI clients" page. */
export type TeamToken = {
    id: number;
    name: string;
    access: TokenAccess;
    access_label: string;
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
};

/** A token just created: flashed once with its plain text, which the server never shows again. */
export type NewTeamToken = {
    name: string;
    plain_text: string;
};

/** An app the signed-in person connected to a team's MCP URL with OAuth (a Claude custom connector, ChatGPT). */
export type TeamConnection = {
    id: number;
    client: string;
    sends_to: string;
    access: TokenAccess;
    access_label: string;
    connected_at: string | null;
};
