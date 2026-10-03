import { useAction, useActionEdits } from '@agentic-actions/client/react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { updateTask } from '@/agentic/actions';
import RefusalAlert from '@/components/board/refusal-alert';
import TaskFields from '@/components/board/task-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { fieldCheck } from '@/lib/board';
import type { BoardMember, BoardProject, BoardTask } from '@/types';

type Props = {
    team: string;
    task: BoardTask | null;
    members: BoardMember[];
    projects: BoardProject[];
    onOpenChange: (open: boolean) => void;
};

/**
 * Posts every field to the generated update-task route. The task number rides along as input; the action finds it
 * through the team scope, so a number from another team reads as not found.
 */
export default function EditTaskDialog({
    team,
    task,
    members,
    projects,
    onOpenChange,
}: Props) {
    return (
        <Dialog open={task !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                {task !== null && (
                    <EditTaskForm
                        key={task.id}
                        team={team}
                        task={task}
                        members={members}
                        projects={projects}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditTaskForm({
    team,
    task,
    members,
    projects,
    onDone,
}: Omit<Props, 'task' | 'onOpenChange'> & {
    task: BoardTask;
    onDone: () => void;
}) {
    const form = useAction(updateTask({ current_team: team }), {
        task: task.id,
        title: task.title,
        description: task.description,
        status: task.status,
        priority: task.priority,
        due_on: task.due_on,
        assignee: task.assignee?.email ?? null,
        project: task.project,
    });

    // While this form has unsaved changes, the assistant's board refreshes wait. They run once it is saved or closed.
    useActionEdits(form.isDirty);

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if ((await form.run()) !== undefined) {
            toast.success('Task saved.');
            onDone();
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <DialogHeader>
                <DialogTitle>Edit task #{task.id}</DialogTitle>
                <DialogDescription>
                    Sent to the update-task action.
                </DialogDescription>
            </DialogHeader>

            <TaskFields
                values={{
                    title: form.data.title ?? '',
                    description: form.data.description ?? null,
                    status: form.data.status ?? task.status,
                    priority: form.data.priority ?? task.priority,
                    due_on: form.data.due_on ?? null,
                    assignee: form.data.assignee ?? null,
                    project: form.data.project ?? null,
                }}
                errors={form.errors}
                members={members}
                projects={projects}
                onChange={(changes) =>
                    form.setData((data) => ({ ...data, ...changes }))
                }
                onValidate={(field) => form.validate(field, fieldCheck)}
            />

            <RefusalAlert message={form.refusal} />

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button type="button" variant="secondary">
                        Cancel
                    </Button>
                </DialogClose>
                <Button type="submit" disabled={form.processing}>
                    Save
                </Button>
            </DialogFooter>
        </form>
    );
}
