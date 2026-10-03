import { ActionError, callAction } from '@agentic-actions/client';
import { CalendarDays, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { updateTask } from '@/agentic/actions';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { formatDueDate, statuses } from '@/lib/board';
import { cn } from '@/lib/utils';
import type { BoardTask, TaskPriority, TaskStatus } from '@/types';

type Props = {
    team: string;
    task: BoardTask;
    onEdit: (task: BoardTask) => void;
    onDelete: (task: BoardTask) => void;
};

const priorityStyles: Record<TaskPriority, string> = {
    high: 'border-transparent bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    normal: 'border-transparent bg-secondary text-secondary-foreground',
    low: 'text-muted-foreground',
};

/**
 * One card. The status select calls update-task with callAction(), the dependency-free client: it resolves the
 * output or throws the server's sentence, and after a success hands the action's touches to the Inertia reload.
 */
export default function TaskCard({ team, task, onEdit, onDelete }: Props) {
    const getInitials = useInitials();
    const [moving, setMoving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const move = async (status: TaskStatus) => {
        setMoving(true);
        setError(null);

        try {
            await callAction(updateTask({ current_team: team }), {
                task: task.id,
                status,
            });
        } catch (thrown) {
            setError(
                thrown instanceof ActionError
                    ? thrown.message
                    : 'Something went wrong.',
            );
        } finally {
            setMoving(false);
        }
    };

    return (
        <div
            className="group space-y-3 rounded-lg border bg-card p-3 text-card-foreground shadow-xs"
            data-test={`task-${task.id}`}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-sm leading-snug font-medium">
                        {task.title}
                    </p>
                    {task.project && (
                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                            {task.project}
                        </p>
                    )}
                </div>
                <div className="flex shrink-0 opacity-60 transition-opacity group-hover:opacity-100">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        onClick={() => onEdit(task)}
                        aria-label="Edit task"
                    >
                        <Pencil className="size-3.5" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        onClick={() => onDelete(task)}
                        aria-label="Delete task"
                    >
                        <Trash2 className="size-3.5" />
                    </Button>
                </div>
            </div>

            {task.description && (
                <p className="line-clamp-2 text-xs text-muted-foreground">
                    {task.description}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <Badge
                    variant="outline"
                    className={priorityStyles[task.priority]}
                >
                    {task.priority}
                </Badge>
                {task.due_on && (
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 text-xs',
                            task.overdue
                                ? 'font-medium text-red-600 dark:text-red-400'
                                : 'text-muted-foreground',
                        )}
                    >
                        <CalendarDays className="size-3" />
                        {task.overdue ? 'Overdue · ' : ''}
                        {formatDueDate(task.due_on)}
                    </span>
                )}
            </div>

            <div className="flex items-center justify-between gap-2">
                {task.assignee ? (
                    <span className="flex min-w-0 items-center gap-2 text-xs">
                        <Avatar className="size-6">
                            <AvatarFallback className="text-[10px]">
                                {getInitials(task.assignee.name)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="truncate">{task.assignee.name}</span>
                    </span>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Unassigned
                    </span>
                )}

                <Select
                    value={task.status}
                    onValueChange={(value) => move(value as TaskStatus)}
                    disabled={moving}
                >
                    <SelectTrigger
                        size="sm"
                        className="h-7 w-[92px] text-xs"
                        aria-label="Status"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {statuses.map((status) => (
                            <SelectItem key={status.value} value={status.value}>
                                {status.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {error && (
                <p className="text-xs text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </div>
    );
}
