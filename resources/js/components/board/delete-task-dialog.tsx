import { useAction } from '@agentic-actions/client/react';
import { toast } from 'sonner';
import { deleteTask } from '@/agentic/actions';
import RefusalAlert from '@/components/board/refusal-alert';
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
import type { BoardTask } from '@/types';

type Props = {
    team: string;
    task: BoardTask | null;
    onOpenChange: (open: boolean) => void;
};

/**
 * delete-task is Destructive: the web route and the CLI serve it, and the assistant is never offered it. A member
 * sees the server's 403 here; owners and admins delete.
 */
export default function DeleteTaskDialog({ team, task, onOpenChange }: Props) {
    return (
        <Dialog open={task !== null} onOpenChange={onOpenChange}>
            <DialogContent>
                {task !== null && (
                    <DeleteTaskConfirmation
                        key={task.id}
                        team={team}
                        task={task}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function DeleteTaskConfirmation({
    team,
    task,
    onDone,
}: {
    team: string;
    task: BoardTask;
    onDone: () => void;
}) {
    const form = useAction(deleteTask({ current_team: team }), {
        task: task.id,
    });

    const confirm = async () => {
        if ((await form.run()) !== undefined) {
            toast.success('Task deleted.');
            onDone();
        }
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>Delete “{task.title}”?</DialogTitle>
                <DialogDescription>
                    Deleting cannot be undone. Only owners and admins may
                    delete, and the assistant never can.
                </DialogDescription>
            </DialogHeader>

            <RefusalAlert message={form.refusal} />

            <DialogFooter className="gap-2">
                <DialogClose asChild>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    disabled={form.processing}
                    onClick={confirm}
                >
                    Delete task
                </Button>
            </DialogFooter>
        </>
    );
}
