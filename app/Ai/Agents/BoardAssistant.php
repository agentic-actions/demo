<?php

namespace App\Ai\Agents;

use AgenticActions\ActionContext;
use AgenticActions\Ai\Concerns\InteractsWithActions;
use AgenticActions\Attributes\UseToolset;
use AgenticActions\Attributes\WithPageContext;
use App\Models\Team;
use App\Models\User;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The board's copilot: one user in one team, with the "default" toolset. Its tools are the actions this user may run in
 * this team, built fresh every turn: an owner or admin also gets delete-task, a member no delete, and a viewer the
 * three Reads only. delete-task is Destructive, so each call waits for the person to confirm it on a card. The package
 * offers it only because this agent stores its conversations (Conversational and RemembersConversations) and is
 * continued for the person before its tools are built.
 *
 * #[WithPageContext] tells the model which page the person has open (a route name and an Inertia component, checked
 * against this app's routes and this team). #[RepairToolCalls] answers a call to a tool it was not given with "does not
 * exist" instead of ending the turn, so the model can say so. The conversation is chosen per user and team by the
 * package's Actions::conversation(); the chat endpoint is App\Http\Controllers\AssistantController.
 */
#[UseToolset]
#[WithPageContext]
#[RepairToolCalls]
class BoardAssistant implements Agent, Conversational, HasMiddleware, HasTools
{
    use InteractsWithActions;
    use Promptable;
    use RemembersConversations;

    /**
     * Build the assistant for one member of one team.
     */
    public function __construct(public User $user, public Team $team) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $role = $this->user->teamRole($this->team)?->label() ?? 'None';
        $members = $this->team->members()->orderBy('name')->get()->map(fn (User $member): string => "{$member->name} <{$member->email}>")->implode(', ');

        return <<<TEXT
            You are the assistant on the {$this->team->name} team's task board. You are talking with {$this->user->name} <{$this->user->email}>, whose role in {$this->team->name} is {$role}. Today is {$this->today()}.

            The board has three columns: todo, doing and done. A task has a number (its id), a title, optional details, a priority (low, normal or high), an optional due date, an optional assignee (a member of this team) and an optional project.

            Your tools are the board actions this person may run in this team, and nothing else. They act as this person, so the team's roles apply: owners and admins do everything, members create and update tasks, viewers only read. If the person asks for something no tool does, say plainly that you cannot, and why.
            - Act on a request instead of asking about it. When the person leaves details open, such as the titles in "add three launch tasks", choose short, sensible ones yourself, make the change, and say what you chose. They can edit any card afterwards. Ask only when you cannot tell what they want at all.
            - Find a task with search-tasks or list-tasks before you change it, and pass the number they return. To move a task to done, complete-task takes its title.
            - Name people by email or full name and projects by name. The members of {$this->team->name} are {$members}. When a tool says a name matched no member and lists the members, pick the one the person meant and try again. "me" in list-tasks means this person.
            - Deleting a task asks the person to confirm it on a card in this chat. Find the task first, call delete-task with its number, and say in one short sentence what you are about to delete: the card shows the details. If they decline, leave the task alone and do not ask again. If you have no delete-task tool, this person's role cannot delete tasks: say so.
            - When a tool answers "Not done", tell the person what it said and do not repeat the same call.
            - The page shows a line for every tool you run, and the board refreshes by itself after a change. Keep replies short, and do not repeat every field back. The panel renders Markdown: use a short list when you name several tasks and bold sparingly, with no headings, tables, images or HTML.
            - Reply in the language the person writes in.
            TEXT;
    }

    /**
     * The step middleware: the page context, from #[WithPageContext].
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [...$this->actionMiddleware()];
    }

    /**
     * The member and the team every tool acts for, from the assistant's own state and never from the request.
     */
    protected function actionContext(): ActionContext
    {
        return ActionContext::agent($this->user, $this->team);
    }

    /**
     * Today's date, so "due tomorrow" and "overdue" mean the same to the model as to the board.
     */
    protected function today(): string
    {
        return today()->format('l, j F Y');
    }
}
