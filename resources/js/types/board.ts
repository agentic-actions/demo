import type { ListTasksOutput, SummarizeBoardOutput } from '@/agentic/actions';

/** One card: exactly what the ListTasks action's outputSchema() lets out. */
export type BoardTask = ListTasksOutput['tasks'][number];

export type TaskStatus = BoardTask['status'];

export type TaskPriority = BoardTask['priority'];

export type BoardSummary = SummarizeBoardOutput;

export type BoardProject = {
    id: number;
    name: string;
};

export type BoardMember = {
    name: string;
    email: string;
};

export type BoardPermissions = {
    createTask: boolean;
    updateTask: boolean;
    deleteTask: boolean;
    createProject: boolean;
};

export type BoardFilters = {
    assignee?: string;
    project?: string;
    priority?: string;
};
