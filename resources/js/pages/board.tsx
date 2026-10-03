import { Head, router, usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useState } from 'react';
import BoardSync from '@/components/board/board-sync';
import CreateProjectDialog from '@/components/board/create-project-dialog';
import CreateTaskDialog from '@/components/board/create-task-dialog';
import DeleteTaskDialog from '@/components/board/delete-task-dialog';
import EditTaskDialog from '@/components/board/edit-task-dialog';
import TaskCard from '@/components/board/task-card';
import TaskSearch from '@/components/board/task-search';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAssistantPanel } from '@/hooks/use-assistant-panel';
import { NONE, priorities, statuses } from '@/lib/board';
import { cn } from '@/lib/utils';
import { board } from '@/routes';
import type {
    BoardFilters,
    BoardMember,
    BoardPermissions,
    BoardProject,
    BoardSummary,
    BoardTask,
} from '@/types';

type Props = {
    filters: BoardFilters;
    tasks: BoardTask[];
    summary: BoardSummary;
    projects: BoardProject[];
    members: BoardMember[];
    role: string | null;
    can: BoardPermissions;
};

export default function Board({
    filters,
    tasks,
    summary,
    projects,
    members,
    role,
    can,
}: Props) {
    const { currentTeam } = usePage().props;
    const team = currentTeam?.slug ?? '';
    const [editing, setEditing] = useState<BoardTask | null>(null);
    const [deleting, setDeleting] = useState<BoardTask | null>(null);
    const { open: assistantOpen, toggle: toggleAssistant } =
        useAssistantPanel();

    const filter = (key: keyof BoardFilters, value: string) => {
        router.get(
            board(team).url,
            { ...filters, [key]: value === NONE ? undefined : value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={`${currentTeam?.name ?? ''} board`} />

            {/* A container, so the layout follows the board's own width: the assistant panel takes 24rem beside it. */}
            <div className="@container flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 @4xl:flex-row @4xl:items-center @4xl:justify-between">
                    <div className="flex shrink-0 items-center gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {currentTeam?.name} board
                        </h1>
                        {role && <Badge variant="secondary">{role}</Badge>}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <TaskSearch team={team} onOpen={setEditing} />
                        <CreateProjectDialog team={team} />
                        <CreateTaskDialog
                            team={team}
                            members={members}
                            projects={projects}
                        />

                        <Button
                            size="sm"
                            variant={assistantOpen ? 'secondary' : 'outline'}
                            onClick={toggleAssistant}
                            aria-pressed={assistantOpen}
                        >
                            <Sparkles />
                            Ask the assistant
                        </Button>
                    </div>
                </div>

                <BoardSync key={team} team={team} />

                {!can.updateTask && (
                    <p className="rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground">
                        Your role reads this board. The controls stay on, so you
                        can watch the server refuse each change.
                    </p>
                )}

                <div className="grid grid-cols-2 gap-2 @xl:grid-cols-3 @2xl:grid-cols-6">
                    <Stat label="To do" value={summary.todo} />
                    <Stat label="Doing" value={summary.doing} />
                    <Stat label="Done" value={summary.done} />
                    <Stat
                        label="Overdue"
                        value={summary.overdue}
                        tone={summary.overdue > 0 ? 'alert' : undefined}
                    />
                    <Stat label="Due in 7 days" value={summary.due_soon} />
                    <Stat label="Unassigned" value={summary.unassigned} />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <FilterSelect
                        value={filters.assignee}
                        placeholder="Everyone"
                        onChange={(value) => filter('assignee', value)}
                        options={[
                            { value: 'me', label: 'Assigned to me' },
                            ...members.map((member) => ({
                                value: member.email,
                                label: member.name,
                            })),
                        ]}
                    />
                    <FilterSelect
                        value={filters.project}
                        placeholder="All projects"
                        onChange={(value) => filter('project', value)}
                        options={projects.map((project) => ({
                            value: project.name,
                            label: project.name,
                        }))}
                    />
                    <FilterSelect
                        value={filters.priority}
                        placeholder="Any priority"
                        onChange={(value) => filter('priority', value)}
                        options={priorities}
                    />
                </div>

                <div className="grid flex-1 gap-4 @2xl:grid-cols-3">
                    {statuses.map((status) => {
                        const column = tasks.filter(
                            (task) => task.status === status.value,
                        );

                        return (
                            <section
                                key={status.value}
                                className="flex flex-col gap-3 rounded-xl bg-muted/50 p-3"
                            >
                                <header className="flex items-center justify-between px-1">
                                    <h2 className="text-sm font-medium">
                                        {status.label}
                                    </h2>
                                    <span className="text-xs text-muted-foreground">
                                        {column.length}
                                    </span>
                                </header>

                                {column.map((task) => (
                                    <TaskCard
                                        key={task.id}
                                        team={team}
                                        task={task}
                                        onEdit={setEditing}
                                        onDelete={setDeleting}
                                    />
                                ))}

                                {column.length === 0 && (
                                    <p className="px-1 py-6 text-center text-xs text-muted-foreground">
                                        Nothing here.
                                    </p>
                                )}
                            </section>
                        );
                    })}
                </div>
            </div>

            <EditTaskDialog
                team={team}
                task={editing}
                members={members}
                projects={projects}
                onOpenChange={(open) => !open && setEditing(null)}
            />
            <DeleteTaskDialog
                team={team}
                task={deleting}
                onOpenChange={(open) => !open && setDeleting(null)}
            />
        </>
    );
}

Board.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Board',
            href: props.currentTeam ? board(props.currentTeam.slug) : '/',
        },
    ],
});

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'alert';
}) {
    return (
        <div className="rounded-lg border px-3 py-2">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={cn(
                    'text-lg font-semibold tabular-nums',
                    tone === 'alert' && 'text-red-600 dark:text-red-400',
                )}
            >
                {value}
            </p>
        </div>
    );
}

function FilterSelect({
    value,
    placeholder,
    options,
    onChange,
}: {
    value: string | undefined;
    placeholder: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <Select value={value ?? NONE} onValueChange={onChange}>
            <SelectTrigger size="sm" className="min-w-36">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE}>{placeholder}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
