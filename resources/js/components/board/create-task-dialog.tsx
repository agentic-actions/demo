import { useAction } from '@agentic-actions/client/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { createTask } from '@/agentic/actions';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import { fieldCheck } from '@/lib/board';
import type { BoardMember, BoardProject, TaskStatus } from '@/types';

type Props = {
    team: string;
    members: BoardMember[];
    projects: BoardProject[];
    status?: TaskStatus;
};

/**
 * Posts to the generated create-task route through useAction(): JSON over Inertia's useHttp, field errors on the
 * fields, anything else in `refusal`. On success the action's touches (tasks, summary) reload exactly those props.
 */
export default function CreateTaskDialog({
    team,
    members,
    projects,
    status = 'todo',
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" data-test="new-task">
                    <Plus />
                    New task
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                {open && (
                    <CreateTaskForm
                        team={team}
                        members={members}
                        projects={projects}
                        status={status}
                        onDone={() => setOpen(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function CreateTaskForm({
    team,
    members,
    projects,
    status,
    onDone,
}: Required<Props> & { onDone: () => void }) {
    const form = useAction(createTask({ current_team: team }), {
        title: '',
        description: null,
        status,
        priority: 'normal',
        due_on: null,
        assignee: null,
        project: null,
    });

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        const output = await form.run();

        if (output !== undefined) {
            toast.success(`Created “${output.task.title}”.`);
            onDone();
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <DialogHeader>
                <DialogTitle>New task</DialogTitle>
                <DialogDescription>
                    Sent to the create-task action, the same one the CLI and the
                    assistant call.
                </DialogDescription>
            </DialogHeader>

            <TaskFields
                values={{
                    title: form.data.title,
                    description: form.data.description ?? null,
                    status: form.data.status ?? 'todo',
                    priority: form.data.priority ?? 'normal',
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
                    Create task
                </Button>
            </DialogFooter>
        </form>
    );
}
