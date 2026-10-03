import { Form, router } from '@inertiajs/react';
import { FolderPlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { createProject } from '@/agentic/actions';
import RefusalAlert from '@/components/board/refusal-alert';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * A plain Inertia <Form> whose action is the generated definition. The package answers the visit like any Laravel
 * form: a 303 back with the result flashed, or errors in the default bag. The duplicate-name refusal lands on
 * "name"; one without a field lands on "action".
 *
 * A 403 is not an Inertia response, so Inertia would open its error modal. <Form> types an onHttpException prop but
 * @inertiajs/react 3.7 does not pass it to the visit, so the dialog listens to the router's httpException event
 * while it is open and shows the refusal itself.
 */
export default function CreateProjectDialog({ team }: { team: string }) {
    const [open, setOpen] = useState(false);
    const [denied, setDenied] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        return router.on('httpException', (event) => {
            event.preventDefault();

            setDenied(
                event.detail.response.status === 403
                    ? 'You are not allowed to do this.'
                    : `The server answered ${event.detail.response.status}.`,
            );
        });
    }, [open]);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setDenied(null);
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <FolderPlus />
                    New project
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    key={String(open)}
                    action={createProject({ current_team: team })}
                    className="space-y-6"
                    options={{ preserveScroll: true }}
                    onStart={() => setDenied(null)}
                    onSuccess={() => {
                        toast.success('Project created.');
                        setOpen(false);
                    }}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>New project</DialogTitle>
                                <DialogDescription>
                                    Owners and admins create projects. Tasks are
                                    filed under a project by its name.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2">
                                <Label htmlFor="project-name">Name</Label>
                                <Input
                                    id="project-name"
                                    name="name"
                                    placeholder="Website relaunch"
                                    aria-invalid={errors.name !== undefined}
                                    autoFocus
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="project-description">
                                    What is it for?
                                </Label>
                                <Textarea
                                    id="project-description"
                                    name="description"
                                    rows={2}
                                />
                                <InputError message={errors.description} />
                            </div>

                            <RefusalAlert
                                message={errors.action ?? denied ?? null}
                            />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    Create project
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
