import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { NONE, priorities, statuses } from '@/lib/board';
import type {
    BoardMember,
    BoardProject,
    TaskPriority,
    TaskStatus,
} from '@/types';

export type TaskFieldValues = {
    title: string;
    description: string | null;
    status: TaskStatus;
    priority: TaskPriority;
    due_on: string | null;
    assignee: string | null;
    project: string | null;
};

type Props = {
    values: TaskFieldValues;
    errors: Partial<Record<string, string>>;
    members: BoardMember[];
    projects: BoardProject[];
    onChange: (changes: Partial<TaskFieldValues>) => void;
    onValidate: (field: keyof TaskFieldValues) => void;
};

/**
 * The fields create-task and update-task share. People are sent by email and projects by name: the same words the
 * actions take from the assistant, so one input vocabulary serves the form and the model.
 */
export default function TaskFields({
    values,
    errors,
    members,
    projects,
    onChange,
    onValidate,
}: Props) {
    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <Label htmlFor="task-title">Title</Label>
                <Input
                    id="task-title"
                    value={values.title}
                    onChange={(event) =>
                        onChange({ title: event.target.value })
                    }
                    onBlur={() => onValidate('title')}
                    placeholder="Draft the launch post"
                    aria-invalid={errors.title !== undefined}
                    autoFocus
                />
                <InputError message={errors.title} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="task-description">Details</Label>
                <Textarea
                    id="task-description"
                    value={values.description ?? ''}
                    onChange={(event) =>
                        onChange({ description: event.target.value || null })
                    }
                    rows={3}
                />
                <InputError message={errors.description} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label>Status</Label>
                    <Select
                        value={values.status}
                        onValueChange={(value) =>
                            onChange({ status: value as TaskStatus })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {statuses.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} />
                </div>

                <div className="grid gap-2">
                    <Label>Priority</Label>
                    <Select
                        value={values.priority}
                        onValueChange={(value) =>
                            onChange({ priority: value as TaskPriority })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {priorities.map((priority) => (
                                <SelectItem
                                    key={priority.value}
                                    value={priority.value}
                                >
                                    {priority.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.priority} />
                </div>

                <div className="grid gap-2">
                    <Label>Assignee</Label>
                    <Select
                        value={values.assignee ?? NONE}
                        onValueChange={(value) =>
                            onChange({
                                assignee: value === NONE ? null : value,
                            })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>Nobody</SelectItem>
                            {members.map((member) => (
                                <SelectItem
                                    key={member.email}
                                    value={member.email}
                                >
                                    {member.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.assignee} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="task-due-on">Due date</Label>
                    <Input
                        id="task-due-on"
                        type="date"
                        value={values.due_on ?? ''}
                        onChange={(event) =>
                            onChange({ due_on: event.target.value || null })
                        }
                        onBlur={() => onValidate('due_on')}
                        aria-invalid={errors.due_on !== undefined}
                    />
                    <InputError message={errors.due_on} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label>Project</Label>
                <Select
                    value={values.project ?? NONE}
                    onValueChange={(value) =>
                        onChange({ project: value === NONE ? null : value })
                    }
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>No project</SelectItem>
                        {projects.map((project) => (
                            <SelectItem key={project.id} value={project.name}>
                                {project.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.project} />
            </div>
        </div>
    );
}
