# Demo guide

This app is a team task board, built on the Laravel React starter kit with teams, to show [Agentic Actions for Laravel](https://github.com/agentic-actions/laravel) in a real Inertia app. Every change on the board is one Action class. The same class answers the board's forms, a JSON route, `php artisan actions:run`, the assistant's tool calls and MCP clients. Teams are the tenants: each action runs inside the team in the URL, and each role gets a different answer.

The scripts below run on a local copy with the seeded users; [README.md](README.md#run-it-locally) sets one up. The package's own documentation is at https://agentic-actions.com.

On the hosted demo, https://demo.agentic-actions.com, you start from "Try the demo" instead of signing in. You are the Acme owner in a sandbox of your own, and "View as" in the bar at the top switches to Marcus, Vera or Gus. Team slugs there end in six random characters, so replace `acme` in a URL below with your sandbox's slug. The copilot always follows the script there, and a Claude.ai connector needs no tunnel: copy the connector URL from the team's AI clients page. The hosted demo has no sign-up, password, email or invitation features, and the sandbox is deleted after 24 hours.

Stage 2 adds the copilot: a chat panel beside the board, on OpenRouter, whose tools are the same actions (see [Copilot](#copilot)). With no OpenRouter key it runs in demo mode, on scripted replies, and everything else stays real.

Stage 3 serves the board to MCP clients, such as Claude Code, Cursor and Claude Desktop, with a token from the team's settings, and the board shows their writes within a few seconds without a reload (see [MCP](#mcp)).

Stage 4 lets the assistant delete a task once you confirm it. It asks with a card built on the server from the task, with Confirm and Decline, and nothing runs until you answer (see [Approvals](#approvals)).

Stage 5 lets the assistant ask you for what it left out. A task it adds needs a priority and a due date, and when the model's call leaves them out, a form built on the server opens in the chat with the title and the assignee filled in. The task is added only when you submit it (see [Asking for missing details](#asking-for-missing-details)).

Stage 6 names those two fields in the package's `requiredForAgents()`, so the form marks them required and MCP clients must give them too. A client that shows forms gets the same form, and any other client gets the refusal naming both (see [Asking over MCP](#asking-over-mcp)).

Stage 7 lets Claude.ai and other remote clients connect with OAuth (package 0.7, on Laravel Passport). You add the board's URL as a custom connector, sign in here, approve one team on a consent page, and the client reaches only that team, with your role there. "Connected apps" on the team's AI clients page lists it and revokes it. A short-lived tunnel gives the app the public HTTPS address such a client needs (see [Connect Claude.ai (custom connector)](#connect-claudeai-custom-connector)).

Stage 8 answers questions about the board with tables and charts (package 0.9). The person sees the rows as a table and a chart, and the model reads a short copy and says what stands out (see [Tables and charts](#tables-and-charts)).

Stage 9 adds two datasets, so the assistant and MCP clients can ask questions no report was written for, such as high-priority tasks per person or tasks completed per week. The package writes the query (see [Datasets](#datasets)).

## How it is wired

| File                                                                                                                     | What it does                                                                                                                                                                             |
| ------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `config/agentic-actions.php`                                                                                             | Tenant model `Team`, route parameter `current_team` (resolved by slug), the two tenancy classes below, and the MCP URL `mcp/t/{current_team}`                                            |
| `app/Tenancy/TeamMembership.php`                                                                                         | The package's membership check. It calls the kit's `belongsToTeam()`                                                                                                                     |
| `app/Tenancy/TeamScope.php`                                                                                              | Scopes `$context->find()` to the team, so a task number from another team is "Not found."                                                                                                |
| `routes/web.php`                                                                                                         | `Actions::routes(tenant: true)` inside the kit's `{current_team}` group, giving `POST /{team}/actions/{action}`                                                                          |
| `app/Actions/Tasks/*`, `app/Actions/Projects/CreateProject.php`                                                          | The eight actions. `authorize()` checks the kit's `TeamPermission` for the user's role                                                                                                   |
| `app/Enums/TeamRole.php`, `TeamPermission.php`                                                                           | The kit's enums, plus a `Viewer` role and four task/project permissions                                                                                                                  |
| `app/Http/Controllers/BoardController.php`                                                                               | Builds the page by running `ListTasks` and `SummarizeBoard` in-process                                                                                                                   |
| `app/Ai/Agents/BoardAssistant.php`                                                                                       | The copilot: a laravel/ai agent whose tools are the actions this person may run in this team                                                                                             |
| `app/Actions/Tasks/DeleteTask.php`                                                                                       | Destructive. `agents: ['default']` offers it to the assistant, which asks you to confirm each delete on a card                                                                           |
| `app/Actions/Tasks/CreateTask.php`                                                                                       | `requiredForAgents()` and `$askForMissing`: a model's call without a priority or a due date opens a form built by `ask()`, in the chat or an MCP client that shows forms                 |
| `app/Http/Controllers/AssistantController.php`                                                                           | `POST /{team}/assistant` streams one turn through `ActionsProtocol`; `GET /{team}/assistant/transcript` returns the words for a reload                                                   |
| `database/migrations/*_create_agentic_conversations_table.php`                                                           | The package's conversation store, from `actions:install`. `Actions::conversation()` in the controller keeps one conversation per user and team, since laravel/ai's store keeps no tenant |
| `app/Ai/ScriptedPlanner.php`                                                                                             | Demo mode: plays the model on laravel/ai's fake gateway when `OPENROUTER_API_KEY` is empty                                                                                               |
| `config/ai.php`                                                                                                          | laravel/ai's config, with OpenRouter as the default provider and `OPENROUTER_MODEL` as its text model                                                                                    |
| `resources/js/components/assistant/*`                                                                                    | The panel: `useChat` with the `/ai-sdk` preset, `messageSegments()`, `<ActionActivity>` rows, Markdown, and `<ElicitationForm>` with `answerElicitation()` in `assistant-form.tsx`       |
| `resources/js/agentic/actions.ts`                                                                                        | Typed action definitions, generated by `php artisan actions:typescript`                                                                                                                  |
| `resources/js/components/board/*`                                                                                        | The UI. Forms use `useAction()`, the status select uses `callAction()`, and New project is a plain Inertia `<Form>`                                                                      |
| `resources/js/components/board/board-sync.tsx`                                                                           | The board's one `useActionSync()`: it follows the assistant's rows and polls the change feed for every other write in the team                                                           |
| `app/Http/Controllers/Teams/TeamTokenController.php`, `app/Enums/TokenAccess.php`, `resources/js/pages/teams/tokens.tsx` | The team's "AI clients" page: Sanctum tokens for MCP, bound to the team, read only or read and write                                                                                     |
| `config/auth.php`, `config/agentic-actions.php`                                                                          | The Passport guard `api`, named after Sanctum in `mcp.middleware` (`auth:sanctum,api`). That name is the switch that turns the package's OAuth on                                        |
| `routes/ai.php`, `config/mcp.php`                                                                                        | laravel/mcp's `Mcp::oauthRoutes()` (the metadata and client registration, throttled), and the callbacks registration accepts: Claude, ChatGPT, VS Code, loopback and Cursor              |
| `app/Providers/AppServiceProvider.php`, `resources/js/pages/oauth/consent.tsx`                                           | Access tokens that last an hour, refresh tokens that last 30 days, and the consent page as an Inertia page built from the package's `Consent`                                            |
| `app/Http/Controllers/Teams/TeamConnectionController.php`                                                                | "Connected apps" on the AI clients page: `McpConnection::for()` lists them, and Revoke calls `revoke()`                                                                                  |
| `app/Http/Middleware/UsePublicUrl.php`, `app/Console/Commands/Tunnel.php`                                                | `APP_PUBLIC_URL`, and `php artisan app:tunnel`, which opens a short-lived public address and sets it                                                                                     |
| `config/demo.php`, `app/Actions/Demo/*`, `app/Http/Controllers/DemoController.php`                                       | The hosted demo: `DEMO_HOSTED`, a sandbox per visitor (`CreateSandbox`), its signed resume link, "View as", and `DeleteSandbox`                                                          |
| `app/Http/Middleware/DisabledOnHostedDemo.php`, `TrustHostedDemoHost.php`                                                | On the hosted demo: the kit's sign-up, password, two-factor, passkey, email and invitation routes refused, and only `APP_URL`'s host answered                                            |
| `app/Console/Commands/PruneSandboxes.php`, `PruneClients.php`, `routes/console.php`                                      | The scheduled clean-up: expired sandboxes every 15 minutes, OAuth clients nobody connected once a day                                                                                    |
| `actions.exposure.json`                                                                                                  | The reviewed list of what each action exposes. `php artisan actions:check` compares against it                                                                                           |

## Seeded users

The password is `password` for all four.

| Email                            | Acme   | Globex |
| -------------------------------- | ------ | ------ |
| owner@example.com (Olivia Park)  | Owner  |        |
| member@example.com (Marcus Reed) | Member | Member |
| viewer@example.com (Vera Lind)   | Viewer |        |
| globex@example.com (Gus Novak)   |        | Owner  |

Owners and admins can do everything. Members create and update tasks but cannot delete tasks or create projects. Viewers can only read.

## Hand-test script

Start from the seeded state with `php artisan migrate:fresh --seed`, then open http://agentic-actions-demo.test/login.

1. Sign in as owner@example.com. You land on the Acme dashboard, which has one card with an "Open the board" button. Click Board in the sidebar.
2. The board has 13 cards: 7 in To do, 3 in Doing, 3 in Done. The Overdue count (2) is red. "Ask the assistant" opens the copilot, which has its own script under [Copilot](#copilot).
3. Click New task, then Create task with no title. "The title field is required." appears under Title.
4. Type a title, click into Details, go back and delete the title, then click into Details again. The same error appears within a second without submitting. That was a Precognition check against the action's `schema()`. Nothing was saved.
5. Fill in a title, priority High, assignee Marcus Reed, project Website relaunch and a due date, then click Create task. A toast says "Created “…”." The card is in To do and the count is 8.
6. On the new card, set the status select to Doing, then to Done. The card moves each time and the counts follow. Only the `tasks` and `summary` props reload, because those are the action's `$touches`.
7. Click the pencil on the card, change the title, and Save. A toast says "Task saved."
8. Type `newsletter` in the search box and press Enter. One result: "#1 Write the launch announcement" (the word is in its description). Click it to open that task, or click the X to clear the box.
9. Set the first filter to "Assigned to me". Five cards are left and the URL ends in `?assignee=me`.
10. Click New project and name it `Mobile app`. "This team already has a project by that name." appears under Name. Rename it, create it, and a toast says "Project created."
11. Click the bin on the card you made and confirm. A toast says "Task deleted." and the card is gone.
12. Sign in as member@example.com. Changing a status works. Deleting a task shows "Not done / You are not allowed to do this." in the dialog, and so does New project. Switch to Globex with the team switcher at the top of the sidebar and open Board: Globex has its own 7 tasks and none of Acme's.
13. Sign in as viewer@example.com. A dashed note says your role only reads the board, but the controls stay on. Change a status: the card stays where it was and shows "You are not allowed to do this." New task and New project are refused the same way.
14. Sign in as globex@example.com and open http://agentic-actions-demo.test/acme/board. You get the kit's plain "403 Forbidden" page, because Gus is not in Acme. To try a task number from another team, open the Globex board, then run this in the browser console:

    ```js
    const xsrf = decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1],
    );
    const post = (url) =>
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrf,
            },
            body: JSON.stringify({ task: 1 }),
        }).then((r) => r.status);
    [
        await post('/globex/actions/delete-task'),
        await post('/acme/actions/delete-task'),
    ];
    ```

    It prints `[404, 403]`. Task 1 is Acme's, so Globex's route says it does not exist, and Acme's route turns Gus away.

Run `php artisan migrate:fresh --seed` again to put the boards back.

## The CLI

`--as` takes a user id (1 owner, 2 member, 3 viewer, 4 globex) and `--tenant` a team slug. Output is JSON.

```bash
php artisan actions:list
php artisan actions:run summarize-board --as=1 --tenant=acme
php artisan actions:run list-tasks assignee=me status=todo --as=1 --tenant=acme
php artisan actions:run create-task title="Call the printer" assignee="Marcus Reed" project="Mobile app" priority=high --as=1 --tenant=acme
php artisan actions:run complete-task task=2 --as=2 --tenant=acme
php artisan actions:run create-task title="Nope" --as=3 --tenant=acme    # "You are not allowed to do this.", exit 3
php artisan actions:run delete-task task=15 --as=1 --tenant=acme         # a Globex task: "Not found.", exit 1
php artisan actions:check                                                  # "Every check passed."
```

In a Pest test, pass `'--as' => '1'` as a string. 0.1 ignores an integer `--as` and runs the action with no user.

## JSON

The generated JSON routes sit in the kit's `web` group, so they answer a signed-in session, and step 14 calls them from the browser. Tokens, since stage 3, are for MCP only (see [MCP](#mcp)): the app has no API routes of its own.

## Copilot

"Ask the assistant" on the board opens a panel on the right. From 1280px wide it docks beside the board, below that it is a sheet over the page, and on a phone it fills the screen. The board lays itself out by its own width, so beside the panel its header stacks, and under 672px its three columns do. You type a request, and `BoardAssistant` runs the board's own actions as you, in the team in the URL. Each tool call shows as a row, "Creating a task…" while it runs and "Created a task" once it has, and when a write succeeds the board reloads its `tasks` and `summary` props without a page visit.

Replies render as Markdown, such as bold words and short lists. The model writes that text, so the panel never renders an image or an unsafe link from it: an image shows only its alt text, a link that is not http(s) shows as plain text, and HTML shows as text.

The rules are the ones from the first script. The assistant is offered only the actions your role may run in this team, so a viewer gets the three Reads and a member gets no delete. An owner's or admin's assistant also gets `delete-task`, which waits for them to confirm each call (see [Approvals](#approvals)). When a model asks for a tool it was not given, laravel/ai answers "Tool '…' does not exist" (the agent carries `#[RepairToolCalls]`), so the model can say so instead of the turn failing.

How it is put together, following the package's `docs/copilot.md`:

- `POST /{team}/assistant` sits inside the kit's `{current_team}` group, so `auth`, `verified` and `EnsureTeamMembership` run first, with the `assistant` limiter on top (10 turns a minute per person, 30 per address, and one reply at a time). It continues this user's conversation in this team, then reads only the newest message, or the answer to a confirmation card, with `ChatRequest::from($request, $assistant)`, and streams the turn through `ActionsProtocol`. `respond()` answers 422 for an empty message and 409 for an answer to a card that is no longer waiting. The stream carries rows and words, never tool calls, their arguments or results.
- Conversations are kept per user and per team with the package's `Actions::conversation()`, in its `agentic_conversations` table. Marcus has one chat in Acme and another in Globex. The demo kept its own `assistant_conversations` table until the package shipped this store; `2026_09_26_112312` copied its rows across and dropped it.
- `GET /{team}/assistant/transcript` returns the words of that one conversation for the panel to show after a reload, the card of a delete still waiting for you, and whether demo mode is on.
- `#[WithPageContext]` tells the model which page you have open, as a route name and a component (`board (board)`), checked against this app's routes and your team.
- The panel is `useChat` from `@ai-sdk/react` with `actionsChat()` from `@agentic-actions/client/ai-sdk`, and `<ActionActivity>` for the rows. It lives in the persistent layout, so it survives Inertia visits, and whether it is open is kept per browser tab.
- The board follows the rows with its own `useActionSync()` (`board-sync.tsx`), the same one that polls the change feed (see [MCP](#mcp)). One sync per page means one reload per burst of writes, whoever made them.
- A reply shows its rows and words in the order they streamed, from the package's `messageSegments()`. A real model often says what it is about to do, runs its tools, then reports, so its rows sit between those two lines. Every reply but the one streaming is passed `{ settled: true }`, so after Stop, an error or a cut stream no row keeps spinning.
- The assistant's words render as Markdown with [Streamdown](https://streamdown.ai), which closes unfinished syntax while a reply streams, so a half-written `**` or link never shows. Model text is untrusted, so the renderer loads no image (it shows the alt text), makes a link only to an http(s) URL, opening in a new tab with `rel="noopener noreferrer nofollow"`, and shows any HTML the model wrote as text. Each block takes the direction of its own words. Your own messages stay plain text. `resources/js/components/assistant/assistant-markdown.test.tsx` (`npm test`) feeds it hostile replies.
- The chat, with the AI SDK and the Markdown renderer, loads the first time the panel opens, so other pages do not carry it.

### Demo mode

With `OPENROUTER_API_KEY` empty, the assistant runs in demo mode and the panel says "Demo mode — scripted replies, add OPENROUTER_API_KEY for a real model". The model is replaced by laravel/ai's own fake gateway, installed on the OpenRouter provider where the real gateway sits, and driven by `App\Ai\ScriptedPlanner`. The planner turns a few requests into tool calls, reads each tool's answer the way a model would, and writes the reply from what it read. Everything else is the real path: the same endpoint and stream, the real actions with their authorization and team scope, the confirmation card, and the conversation store. The rows, the refusals and the board refreshes you see are real.

The planner follows these requests, and gives a plain reply to anything else:

| You type                                         | Tool calls                                                             |
| ------------------------------------------------ | ---------------------------------------------------------------------- |
| What's overdue?                                  | `list-tasks` with `overdue: true`                                      |
| Add three launch tasks for Marcus                | `create-task` three times (one to five tasks; "for me" works too)      |
| Add a task for Marcus to review the pricing page | `create-task` with the title and Marcus only, which waits for the form |
| Move 'Design the empty states' to done           | `complete-task` with the title                                         |
| Delete 'Order new office chairs'                 | `search-tasks`, then `delete-task`, which waits for your confirmation  |
| How are we doing?                                | `summarize-board`                                                      |
| Tasks by status                                  | `tasks-by-status`                                                      |
| Who has the most tasks?                          | `tasks-by-assignee`                                                    |
| Tasks completed per day                          | `completed-per-day`                                                    |
| Tasks per project                                | `task-stats` with `by: [project]`                                      |
| High-priority tasks per person                   | `task-stats` by assignee, filtered to high priority                    |
| Tasks completed per week                         | `completion-stats` with `grain: week` over eight weeks                 |

Demo mode waits 400 ms before each step and 800 ms before each tool, so the rows tick at a pace you can follow. `ASSISTANT_DEMO_PACE_MS` changes the step wait, and `0` turns the waiting off (the tests do).

### A real model

1. Get a key at https://openrouter.ai/keys and put it in `.env`:

    ```dotenv
    OPENROUTER_API_KEY=<your OpenRouter key>
    OPENROUTER_MODEL=anthropic/claude-sonnet-5
    ```

2. Run `php artisan config:clear` if you cached the config, and reload the board. The demo-mode line is gone.

`OPENROUTER_MODEL` takes any model id from https://openrouter.ai/models that supports tool calling. The default, `anthropic/claude-sonnet-5`, is also laravel/ai's own OpenRouter default. `anthropic/claude-haiku-4.5` is cheaper and faster, and `anthropic/claude-opus-5` is stronger. The tests force the key empty in `phpunit.xml`, so they never reach OpenRouter.

The hand-test script below runs with a real model too. The rows, their labels and every refusal come from the same actions, but the model words its replies itself, and a few things look different:

- There is a "Thinking…" line for a few seconds before the first row, while the model writes its call.
- A row shows "Created a task" almost at once, because the action runs in milliseconds. The running state only flashes. Demo mode's pause is what makes it readable there.
- "Add three launch tasks for Marcus" makes up three titles and adds them. The instructions tell the model to fill in open details and say what it chose instead of asking, and they list the team's members, so it usually passes "Marcus Reed" and there is no refused row. If it passes "Marcus", it gets the refusal with the member list and tries again, as the planner does. When it leaves out the priorities or the due dates, each call asks for them in a form (see [Asking for missing details](#asking-for-missing-details)).
- The model may run `search-tasks` before a change, so "Move 'Design the empty states' to done" can show two rows.
- "Delete 'Order new office chairs'" shows the same card as in demo mode, usually after a line from the model saying what it is about to delete. After you answer, the model words its own reply.
- "Add a task for Marcus to review the pricing page" usually shows the same form. `create-task`'s description tells the model it may call with what it knows, and the instructions list the team's members, so it names Marcus Reed in full and he opens selected. A priority the model picks opens selected too. If the model makes up a due date, the call is complete and the task is added at once, with no form.

### Hand-test script

Start from `php artisan migrate:fresh --seed`, in demo mode. The replies below are the planner's.

1. Sign in as owner@example.com, open Board and click "Ask the assistant". The panel opens on the right with "Assistant · Acme", the amber demo-mode line, "Ask about the Acme board" and five suggestion chips. The button stays pressed while the panel is open.
2. Click "What's overdue?". A row "Listing tasks…" with a pulsing blue dot turns into "Listed tasks" with a green one. The reply is Markdown: "**2 tasks** are overdue:" in bold, then a bulleted list of "Fix broken links on the pricing page (Marcus Reed, due …)" and "Crash on login with a long password (unassigned, due …)". A Read changes nothing, so the board does not reload.
3. Click "Add three launch tasks for Marcus". The first row stays at "Creating a task…" with an amber "Not done": "Marcus" is not a member's full name or email, so `create-task` refused and listed the team's members. The planner picks Marcus Reed from that list, as a model would, and three rows tick from "Creating a task…" to "Created a task", one at a time. The To do count climbs from 7 to 10 as they land. The reply says "I added 3 tasks for Marcus Reed: …", and the three new cards are in To do, assigned to Marcus Reed.
4. Click "Move 'Design the empty states' to done". "Moving a task to done…" turns into "Moved a task to done", and the card moves from Doing to Done.
5. Click "Delete 'Order new office chairs'". You get one row, "Searched tasks", then a card asking you to confirm the delete. Click Decline: the row reads "Declined", the reply is "Okay, I left “Order new office chairs” on the board.", and the card is still in To do. [Approvals](#approvals) walks through the card.
6. Type `Move 'Cancel the Elm Street lease' to done` and press Enter. That is a Globex task. The row "Moving a task to done…" gets a "Not done" note, and the reply is "I couldn't move “Cancel the Elm Street lease”: No open task has that title." Acme's assistant cannot see Globex's tasks.
7. Click "Add three launch tasks for Marcus" again and, straight away, click the pencil on any card and change its title without saving. The rows finish, but the board does not move under your dialog, and a line above the counts says "The board changed. It catches up when you close the task you are editing." Close the dialog with Cancel, or Save it: the board catches up at once and the To do count goes up by three. The package holds the refresh while an editor is dirty, and runs it by itself once none is.
8. Reload the page. The panel is still open, since it is kept per tab, and the conversation's words are back. The rows are not: rows are live only, and the conversation store keeps words.
9. Click "Add three launch tasks for Marcus" and press the square Stop button once the first row shows. The row stops pulsing and turns grey ("ended", from `{ settled: true }`), and a line under it says "You stopped following this reply. The assistant still finishes it, so reload to see what it did." Stop ends the display, not the turn: the board stops following, and the server finishes the turn anyway. Reload and the tasks and the reply are there.
10. Sign in as viewer@example.com and open the panel. "What's overdue?" works as in step 2. "Add three launch tasks for Marcus" runs no row and changes nothing, and the reply is "I can't add tasks here. You're a Viewer in Acme, and I only get the tools your role allows, so nothing changed." A viewer's assistant is never offered a write, so there is nothing for the server to refuse: the model is told the tool does not exist. To watch the server refuse a write, use step 3 or step 6.
11. Sign in as member@example.com, chat in Acme, then switch to Globex with the team switcher and open Board. The panel shows "Ask about the Globex board" with no messages. Switch back to Acme and your Acme chat is there.
12. Sign in as globex@example.com, open the dashboard, and run this in the browser console:

    ```js
    const xsrf = decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1],
    );
    [
        (
            await fetch('/acme/assistant', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json, text/event-stream',
                    'X-XSRF-TOKEN': xsrf,
                },
                body: JSON.stringify({
                    messages: [
                        {
                            id: 'm1',
                            role: 'user',
                            parts: [{ type: 'text', text: "What's overdue?" }],
                        },
                    ],
                }),
            })
        ).status,
        (
            await fetch('/acme/assistant/transcript', {
                headers: { Accept: 'application/json' },
            })
        ).status,
    ];
    ```

    It prints `[403, 403]`. Gus is not in Acme, so he reaches neither its assistant nor anyone's conversation there.

Run `php artisan migrate:fresh --seed` to put the boards back. It empties the conversations too.

### What the tests cover

`tests/Feature/Board/AssistantEndpointTest.php` posts to the endpoint through the real kernel, in demo mode, and reads the stream the way `useChat` does. It checks that a turn streams `data-action` rows and words and never a tool part, that rows go from their running label to their finished one and carry `touches`, that a refused write shows a refused row and changes nothing, that a viewer's write changes nothing, that the page context reaches the model only for this team's pages, that a Globex user cannot reach Acme's assistant or its transcript, and that the transcript endpoint returns only the signed-in user's conversation in that team. One test sets a key and uses `BoardAssistant::fake()` to run a model's tool calls through the same stream.

`resources/js/components/assistant/assistant-markdown.test.tsx` (`npm test`) renders replies through the panel's Markdown renderer: a list and bold words, an image to an outside URL (no `<img>`, only its alt text), links to `javascript:`, `data:`, `vbscript:` and relative paths (text, no link), an http(s) link (new tab, `noopener noreferrer nofollow`), raw `<script>`, `<img onerror>`, `<iframe>` and `<a>` (shown as text), half-written `**` and links mid-stream, and the direction of Arabic and English blocks.

## MCP

The package serves the board to MCP clients, such as Claude Code, Cursor and Claude Desktop, at one URL per team: `http://agentic-actions-demo.test/mcp/t/acme` for Acme and `http://agentic-actions-demo.test/mcp/t/globex` for Globex. A client connects with a token from the team's "AI clients" page and calls the same actions the assistant does, as the token's person, inside the URL's team. Membership, `authorize()` and validation run on every call, as on every other surface.

What a client is offered on `/mcp/t/acme`:

| Token                                            | Tools                                                                                                                                                           |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Olivia (owner), Read and write                   | the eight reads below, and `complete-task`, `create-project`, `create-task` and `update-task`                                                                   |
| Marcus (member), Read and write                  | the same, without `create-project`                                                                                                                              |
| Read only, or Vera (viewer) with any token       | the eight reads: `completed-per-day`, `completion-stats`, `list-tasks`, `search-tasks`, `summarize-board`, `task-stats`, `tasks-by-assignee`, `tasks-by-status` |
| a token bound to Globex, or a person not in Acme | none. The list is empty, and a call answers `Tool [create-task] not found.`                                                                                     |

A `create-task` call must give a priority and a due date, as the assistant's must. The tool marks both required, and a call without them is refused with "Not done. Rejected: priority (required), due_on (required).", or asks the person in a client that shows forms (see [Asking over MCP](#asking-over-mcp)).

Nobody gets `delete-task`: MCP has no step where a person confirms a call, so the package never serves a Destructive action over it. The assistant asks on a card instead (see [Approvals](#approvals)). Every write carries `destructiveHint`, so a client that asks before destructive calls asks before each write. A refusal comes back as a failed call with the action's own sentence, such as "No open task has that title."

How it is put together, following the package's `docs/mcp.md`:

- `config/agentic-actions.php` sets `mcp.path` to null and `mcp.tenant_path` to `mcp/t/{current_team}`, since every board action is tenant-scoped. The segment is the team's slug. The middleware is `auth:sanctum,api` and the package's throttle (60 requests a minute per person). Sanctum reads the tokens from this page, and Passport's `api` guard reads the tokens of clients that signed in with OAuth (see [Connect Claude.ai (custom connector)](#connect-claudeai-custom-connector)). Sanctum stays first, or every Sanctum token would answer 401.
- Sanctum came in with `php artisan install:api`. Its `GET /api/user` route checks no ability, so it was deleted with `routes/api.php`, and a token reaches nothing but MCP. `User` uses `HasApiTokens`.
- The "AI clients" page (`TeamTokenController`) mints a token with `createToken()` and three named abilities: `actions:read`, `actions:write` for Read and write only, and `tenant:{team id}`, which binds it to that one team. Never Sanctum's default `*`, which lists no tools over MCP. A token lasts 90 days. Its plain text is flashed once, and Inertia keeps flash data out of the browser history. The list shows each of your tokens for that team with its last use, and Revoke deletes it. Nobody sees or revokes anyone else's token.
- The board polls the change feed, `POST /{team}/actions/_changes`, every 5 seconds while the tab is visible and in use (`board-sync.tsx`; the package's default is 15). A write in the team, over MCP, by the assistant, by a teammate or in another tab, answers the next poll with the keys it touched, and only the `tasks` and `summary` props reload, never the page. An open edit dialog with unsaved changes holds the reload, as it holds the assistant's. The poller pauses after 10 minutes with no input and starts again on the next one.

### Hand-test script

Start from `php artisan migrate:fresh --seed`. It also deletes every token.

1. Sign in as owner@example.com, open Settings, then Teams, then Acme, and click "Manage tokens" under "AI clients", or go to http://agentic-actions-demo.test/settings/teams/acme/tokens. The page shows the MCP URL `http://agentic-actions-demo.test/mcp/t/acme`.
2. Name a token `Claude Code`, leave Access on "Read and write", and click Create token. A green box shows the token (`1|…`) with "Copy the token now: it is not shown again." "Connect a client" at the bottom now has it filled into the three setups below. Reload the page: the box is gone, and "Your tokens" lists "Claude Code", marked "Read and write", "Never used" and its expiry date.
3. Connect a client with that token (see [Connect a client](#connect-a-client)). In Claude Code, `/mcp` shows `agentic-demo-acme` as connected, with the twelve tools of the table above and no `delete-task`.
4. Open http://agentic-actions-demo.test/acme/board in the browser and leave it open. Ask the client: "On the Acme board, add a high-priority task 'Call the printer' for Marcus Reed, due next Friday." Approve the call if the client asks. It runs `create-task`, which answers "Done." Within about 5 seconds the card appears in To do and the count goes from 7 to 8, with no page reload.
5. Ask: "Move 'Call the printer' to done." It runs `complete-task`, and the card moves to Done on the open board within about 5 seconds. Ask it to delete a task: it has no delete tool, so it says so, and the board keeps the task.
6. Ask: "Mark 'Cancel the Elm Street lease' as done." That is a Globex task, so `complete-task` fails with "No open task has that title." and lists Acme's open tasks. Nothing changes.
7. Reload the tokens page: "Claude Code" now says "Last used just now".
8. Make a second token, "Read only", with Access "Read only", and connect it under another name, such as `agentic-demo-acme-read`. It lists only the eight reads, so a request to add a task finds no tool for it.
9. Sign out, sign in as globex@example.com, and make a token at http://agentic-actions-demo.test/settings/teams/globex/tokens. Point a client at `http://agentic-actions-demo.test/mcp/t/acme` with it: the tool list is empty. The same token on `/mcp/t/globex` lists Globex's tools. A token Marcus makes for Globex is empty on Acme too, although he is in both teams.
10. Back as the owner, click Revoke on "Claude Code". The client's next request is turned away with 401 Unauthorized.
11. For the feed without MCP: open the Acme board as the owner, and as member@example.com in a private window. Change a status in one window. The other window catches up within about 5 seconds.

To check the server without a client, use the MCP Inspector CLI or curl. Keep the token in single quotes, since it contains a `|`:

```bash
TOKEN='1|…'
npx -y @modelcontextprotocol/inspector --cli http://agentic-actions-demo.test/mcp/t/acme --transport http \
  --header "Authorization: Bearer $TOKEN" --method tools/list
npx -y @modelcontextprotocol/inspector --cli http://agentic-actions-demo.test/mcp/t/acme --transport http \
  --header "Authorization: Bearer $TOKEN" --method tools/call \
  --tool-name create-task --tool-arg 'title=Filed from the Inspector' --tool-arg 'assignee=Marcus Reed' \
  --tool-arg priority=high --tool-arg due_on=2026-10-15
curl -s http://agentic-actions-demo.test/mcp/t/acme -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Leave out `priority` and `due_on` and the Inspector's CLI gets the refusal naming both: it shows no forms. Without a token, curl gets `401` with a `WWW-Authenticate: Bearer` header. With the Inspector, a call to a tool the token does not list stops on the Inspector's side (`Tool 'create-task' not found on server.`); curl shows the server's own answer, `Tool [create-task] not found.`

### Connect a client

The tokens page shows these three setups with your new token filled in, each with a copy button. Below, `1|…` stands for the token. The server name, `agentic-demo-acme`, is only the client's label.

**Claude Code.** Run:

```bash
claude mcp add --transport http agentic-demo-acme http://agentic-actions-demo.test/mcp/t/acme \
  --header "Authorization: Bearer 1|…"
```

It is added for the current project directory. Add `--scope user` to have it in every project. `/mcp` inside Claude Code shows the server and its tools, and `claude mcp remove agentic-demo-acme` removes it.

**Cursor.** Add this to `.cursor/mcp.json` in a project, or to `~/.cursor/mcp.json` for every project:

```json
{
    "mcpServers": {
        "agentic-demo-acme": {
            "url": "http://agentic-actions-demo.test/mcp/t/acme",
            "headers": { "Authorization": "Bearer 1|…" }
        }
    }
}
```

**Claude Desktop.** Its "Add custom connector" screen signs in with OAuth, which works only through a public address (see [Connect Claude.ai (custom connector)](#connect-claudeai-custom-connector)). With a token, the config file starts a local program instead, so the token goes through the `mcp-remote` bridge. Add this to `~/Library/Application Support/Claude/claude_desktop_config.json` and restart Claude Desktop:

```json
{
    "mcpServers": {
        "agentic-demo-acme": {
            "command": "npx",
            "args": [
                "-y",
                "mcp-remote",
                "http://agentic-actions-demo.test/mcp/t/acme",
                "--header",
                "Authorization:${MCP_AUTH}",
                "--allow-http"
            ],
            "env": { "MCP_AUTH": "Bearer 1|…" }
        }
    }
}
```

Keep the header without a space after the colon and the token in `env`, as above. `--allow-http` is there because the URL is plain `http://` on a `.test` host.

Claude Desktop starts the bridge without your shell's `PATH`. When Node comes only from Herd, plain `npx` fails with "spawn npx ENOENT". Use the full path that `which npx` prints as `command`, and put its folder first on `PATH` in `env`, since `npx` itself looks for `node` there:

```json
"command": "<node bin>/npx",
"env": { "MCP_AUTH": "Bearer 1|…", "PATH": "<node bin>:/usr/bin:/bin" }
```

`<node bin>` is the folder `dirname "$(which node)"` prints, written out in full, since JSON does not expand `~`. With Herd it ends in `Herd/config/nvm/versions/node/v22.22.2/bin`.

### Asking over MCP

A `create-task` call over MCP that leaves out the priority or the due date asks for them when the client can show a form, as the assistant's call does. That is a client on protocol 2026-07-28 whose request declares form elicitation in its `_meta`. Its call gets an `InputRequiredResult`: one `elicitation/create` request holding the panel's form in MCP's standard keys ("Give this task a due date and a priority, and I'll add it.", the title and the assignee opening on the call's values, Normal priority, and Title, Priority and Due date required) and an encrypted `requestState`. The client shows the form and sends the same call again with the answer. The task is added then, once, as the token's person, in the URL's team, and the client's model reads "The person filled in: title, priority, due_on, assignee. Done.", never the values. Decline and cancel add nothing. An answer the rules refuse, such as "soon" for the date, gets the form again with the message after the field's help line. Every other client gets the refusal naming both fields.

Which clients show the form, as tried on 26 September 2026 against a copy of this app (package 0.6.0-alpha.1, laravel/mcp 1.0.1), each calling `create-task` with a title and an assignee only:

| Client                                                                                                                                         | Protocol                 | What it got                                                                                                                                                                                                  |
| ---------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `@modelcontextprotocol/client` 2.1.0 (the official TypeScript client), with an `elicitation/create` handler, pinned to 2026-07-28 or on `auto` | 2026-07-28               | **The form.** Accept added the task once with the handler's values; Decline and Cancel added nothing; an answer with priority "urgent" and due date "soon" was asked again with both messages, then accepted |
| the same client declaring no elicitation, or on `legacy` (the `initialize` handshake) with form elicitation declared                           | 2026-07-28 or 2025-11-25 | the refusal                                                                                                                                                                                                  |
| MCP Inspector 2.8.0's CLI (`--cli`), on the legacy, modern and auto eras                                                                       | 2025-11-25 or 2026-07-28 | the refusal: the CLI declares no elicitation                                                                                                                                                                 |
| laravel/mcp 1.0.1's `Client`, pinned to 2026-07-28 and to 2025-11-25                                                                           | as pinned                | the refusal: it sends empty client capabilities, offers no way to declare elicitation, and has no handler for `input_required`                                                                               |
| curl, with the 2026-07-28 request below                                                                                                        | 2026-07-28               | the form, and a task on the retry                                                                                                                                                                            |

Not run against this app: the MCP Inspector's web page, which shows the form on the modern era (`--protocol-era modern`) in the package's own probe but checks a tool's required fields before it sends a call, so it asks for the priority and the due date in its own tool form and never sends the incomplete call; and Claude Code, Cursor and Claude Desktop. Claude Desktop's `mcp-remote` bridge (0.14) negotiated 2025-11-25 in the package's probe, which gets the refusal. So today a person sees this form in a client built on the official TypeScript client 2.x with a form handler, and in the Inspector's web page.

The request state is not spent by its retry. Sent twice, the same retry adds the task twice (tried with curl), because over MCP the package treats a retry as another call by the same token, which could send the complete call itself. The panel's answer, by contrast, runs once and a second one gets 409. The form reaches the client with everything in it, here the team's members as the assignee's choices.

To see the form with curl, send the call as a 2026-07-28 client that declares form elicitation:

```bash
TOKEN='1|…'
META='{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{"elicitation":{"form":{}}}}'
curl -s http://agentic-actions-demo.test/mcp/t/acme -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: tools/call' -H 'Mcp-Name: create-task' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"create-task","arguments":{"title":"Call the printer","assignee":"Marcus Reed"},"_meta":'"$META"'}}'
```

It answers `"resultType": "input_required"` with the form and a `requestState`. The retry is the same call with a new `id`, plus `"inputResponses": {"create-task": {"action": "accept", "content": {"title": "Call the printer", "priority": "high", "due_on": "2026-10-15", "assignee": "Marcus Reed"}}}` and `"requestState": "<the state>"` in `params`. Without the `_meta`, the same call gets the refusal.

### What the tests cover

`tests/Feature/Board/McpTest.php` sends JSON-RPC to `/mcp/t/{team}` through the real kernel with real Sanctum tokens, and its `create-task` calls give a priority and a due date. It checks the tool list for a read-and-write token, a read-only token, a viewer, Sanctum's default `*` and no token (401), and the tools' hints. It checks that `create-task` writes as the token's person in the URL's team, that a refusal comes back as an error result, that a read-only token cannot create, that `delete-task` never runs, and that a token bound to Globex, a person outside Acme and an unknown team all get an empty list. It also checks that a write over MCP reaches a teammate's feed poll as `["tasks", "summary"]`, that Acme's feed hears nothing of Globex's writes, and that the feed does not answer a bearer token.

`tests/Feature/Board/McpFormTest.php` calls `create-task` without a priority or a due date. It checks that the tool list marks both required and not nullable; that a client declaring no elicitation gets "Not done. Rejected: priority (required), due_on (required)." and no task; that a 2026-07-28 client declaring form elicitation gets `input_required` with the form (the three required fields, the call's title and assignee and Normal priority as defaults, no default due date) and no task; that its retry with an accepted answer adds one task, as the token's person, in Acme, with the answer's values, while the result names only the filled fields; and that a declined form adds nothing.

`tests/Feature/Teams/TeamTokenTest.php` covers the tokens page: only your own tokens for that team are listed, a new token gets the chosen abilities, the team binding and a 90-day expiry, its plain text is flashed once and authenticates, the name and access are validated, someone outside the team is refused, and nobody can revoke another person's token or one bound to another team.

## Connect Claude.ai (custom connector)

Claude.ai, Claude Desktop and mobile (which share claude.ai's connectors) and ChatGPT connect to an MCP server from their own cloud. They have no field for a token: they sign in with OAuth. On a 401 from `/mcp/t/acme` the client reads the metadata, registers itself, and sends you to this app. You sign in and see the consent page, "Connect Claude to Agentic Actions Demo?", with what it may do, the team and where you go next. After Allow, its token reaches only Acme's MCP URL, with your role there, and never `delete-task`. The client refreshes its hour-long access token by itself.

Claude connects from the internet, so the app needs a public HTTPS address for as long as the connector is in use. `php artisan app:tunnel` opens a short-lived Cloudflare quick tunnel to the Herd site for that.

### One-time steps

A copy set up as in the README has steps 3 and 4 done already. On a new copy:

1. `brew install cloudflared`. A quick tunnel needs no Cloudflare account.
2. `herd secure agentic-actions-demo`: the tunnel reaches the site over HTTPS.
3. `php artisan passport:keys`: Passport's key pair, in `storage/` and ignored by git. Without it the MCP URL answers 500 where it should answer 401, and `php artisan actions:check` fails.
4. `php artisan migrate`: Passport's tables and the package's `agentic_mcp_connections`.

### Change the demo passwords first

The tunnel puts the sign-in page on the internet, and every seeded account's password is `password`. Give every account a long password only you know before you open it:

```bash
printf 'New password for the demo accounts: ' && read -rs DEMO_PASSWORD && echo
DEMO_PASSWORD="$DEMO_PASSWORD" php artisan tinker --execute 'App\Models\User::query()->each(fn ($user) => $user->forceFill(["password" => Illuminate\Support\Facades\Hash::make(getenv("DEMO_PASSWORD"))])->save());'
```

`php artisan app:tunnel` refuses to start while any account still has `password`, and names the accounts. The hand-test scripts in this guide sign in with `password`. Once the tunnel is closed, you can set it back the same way.

### Open the tunnel

```bash
php artisan app:tunnel              # 30 minutes; --minutes=10 for less; Ctrl-C closes it sooner
```

It prints the public address and each team's connector URL:

```
  INFO  The tunnel is open for 30 minutes: https://<words>.trycloudflare.com. Ctrl-C closes it sooner.

  Team ............................................ Connector URL (paste it into Claude)
  Acme ................................... https://<words>.trycloudflare.com/mcp/t/acme
  Globex ............................... https://<words>.trycloudflare.com/mcp/t/globex
```

While it runs, `APP_PUBLIC_URL` in `.env` holds the address. Requests that come through the tunnel build every URL on it (the metadata, the 401, the sign-in redirects) and never show a debug page. Requests to https://agentic-actions-demo.test are untouched. The team's AI clients page shows the connector URL under "Connected apps", with a copy button. When the tunnel closes, the command empties `APP_PUBLIC_URL` again. If the command was killed before it could, empty it yourself. The address changes every time, so a connector added with an earlier one stops working when its tunnel closes.

### Add the connector in Claude

1. On claude.ai, open Settings, then Connectors, and click "Add custom connector".
2. Name it "Agentic Actions Demo (Acme)". For the URL, paste Acme's connector URL exactly as printed, with no trailing slash. Leave the advanced settings (client ID and secret) empty: Claude registers itself.
3. Click Add, then Connect. A tab opens on the tunnel's address. Sign in as owner@example.com with the password you set.
4. The consent page says "Connect Claude to Agentic Actions Demo?". It lists "Read what you can see" and "Create and change what you can change", then "Only in the Acme team, with your role there." and "After you answer, you go to claude.ai.", signed in as owner@example.com. Click Allow.
5. Back in Claude, the connector shows as connected with the twelve tools of the table in [MCP](#mcp), and no `delete-task`. In a chat, ask: "Using Agentic Actions Demo, how is the Acme board doing?" It calls `summarize-board`. Ask it to add a task for Marcus with a priority and a due date, then watch https://agentic-actions-demo.test/acme/board: the card appears within about 5 seconds.

A connector's URL names one team. For Globex, add a second connector with Globex's URL and sign in as a Globex member. An Acme token on Globex's URL lists no tools, even for Marcus, who is in both teams.

### Revoke and close

- Settings, then Teams, then Acme, then AI clients: "Connected apps" lists "Claude", "Read and write", "Returns to claude.ai" and when it connected. Revoke ends its access token and refresh token at once. Its next call answers 401, and Claude asks you to connect again. Leaving a team, or being removed from it, revokes your apps for that team too. Removing the connector in Claude revokes nothing here, so revoke it here as well.
- Press Ctrl-C where `php artisan app:tunnel` runs, or let the time run out. The connector stops reaching the app. Remove it in Claude, since its address will not come back.

### When Claude cannot connect

| What happens                                   | Why                                                      | Fix                                                                  |
| ---------------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------------------- |
| "Couldn't reach the MCP server"                | the tunnel is closed, or its new name has not spread yet | open it again; wait half a minute after it prints the address        |
| "This app cannot be connected to this address" | a mistyped team slug, or a team you are not in           | paste the URL `app:tunnel` printed; sign in as a member of that team |
| the sign-in fails                              | the demo passwords were changed                          | use the password you set                                             |
| connected, but it keeps asking to connect      | you revoked it, or the tunnel changed address            | connect again, or add the connector again with the new URL           |

### What the tests cover

`tests/Feature/Teams/OAuthConsentTest.php` runs a Claude-shaped client in one process (`tests/Support/OAuthClient.php`): registration with Claude's callback, the authorization request with Acme's URL as `resource` and S256 PKCE, and the consent page's props (the client, `claude.ai`, Acme, the two abilities in plain words, and Passport's approve and deny forms), which also cannot be framed. Allow redirects to Claude's callback with a code and records the connection. The token lasts an hour and lists Acme's tools, and none on Globex for Marcus, who is in both. Deny sends back `access_denied`, a signed-out person is sent to log in, and a team the person is not in answers 403. `tests/Feature/Teams/TeamConnectionTest.php` lists only your apps for that team, shows the connector URL while `APP_PUBLIC_URL` is set, and checks that Revoke ends the access token (401) and the refresh token (`invalid_grant`), that another person's connection or another team's is not found, and that leaving or being removed from a team revokes yours there. `tests/Feature/PublicUrlTest.php` checks that the metadata and the 401 name the public address for a request through the tunnel (the host, or `X-Forwarded-Host`), and that those requests never see a debug page. Other requests, and every request while `APP_PUBLIC_URL` is empty, keep their own host. `tests/Feature/TunnelCommandTest.php` checks that `app:tunnel` refuses while an account has the seeded password. `tests/TestCase.php` gives Passport a key pair of its own, so the suite never reads `storage/oauth-*.key`.

On 27 September 2026 the whole flow ran on a copy of this app over Herd's HTTPS, as Claude runs it, with headless Chrome for the person. The steps were the 401, both metadata documents, registration, the authorization request, and the sign-in through the Inertia login form. Then the consent page, Allow, and the redirect to `https://claude.ai/api/mcp/auth_callback` with a code. The token lasted an hour, and `initialize`, `tools/list` and `summarize-board` answered. The same token listed no tools on Globex and its call answered "Tool not found", as owner and as Marcus. A refresh worked. After Revoke under Connected apps, the access token answered 401 and the refresh token `invalid_grant`. A quick tunnel to that copy also served the 401, both metadata documents and a registration, with every URL in them on the tunnel's https address.

## Approvals

`delete-task` is Destructive, and since stage 4 the assistant may call it, but never on its own say. Each call pauses the turn: the panel shows a card the server built from the task, and the delete runs only after you confirm it, once, as you, in the team you were asked in. Decline, and nothing runs and the model is told. This follows "Confirmations" in the package's `docs/copilot.md`.

What it takes in this app:

- `DeleteTask` has `#[Expose(web: true, agents: ['default'])]`. A bare `#[Expose]` keeps a Destructive action away from agents, so naming the toolset is what offers it. MCP still never serves it.
- `approvalReason()` is the card's sentence, "Delete this task? This cannot be undone." `approvalSummary()` builds its rows from the task `authorize()` allowed, found again through the team scope: Task, Project and Assignee. The browser gets that sentence and those rows as plain text, never the model's arguments.
- `authorize()` takes `ValidatedInput` and finds the task through `$context->find()`, so a card can only show a task of this team that you may delete. `actions:check` fails a confirmed action whose `authorize()` takes no input (the Summary row). Because the check needs the task, the role check also sits in `shouldRegister()` for model surfaces, which keeps `delete-task` off a member's or viewer's list. The board's own bin still tells them "You are not allowed to do this."
- `BoardAssistant` stores its conversations (`Conversational` with laravel/ai's `RemembersConversations`), and the endpoint continues your conversation in this team before it reads the request. An assistant without a stored conversation is never offered `delete-task`.
- `AssistantController::stream()` passes the assistant to `ChatRequest::from()`, returns through `respond()`, and passes `messageId` to `ActionsProtocol`, so the reply after an answer continues the message that asked. `transcript()` passes the assistant to `Transcript::forUseChat()`, which rebuilds a waiting card on reload.
- The panel renders `approvalCard()` of the newest message with the package's `<ApprovalCard>` and `useChat`'s `addToolApprovalResponse()`, and `actionsChat()` sends the answer by itself. The card comes unstyled, and `resources/css/app.css` styles it through `[data-agentic-approval]`. Its labels, "Waiting for your confirmation", Confirm and Decline, come from the server.
- Confirmations are kept in the default cache store (`database` here) for 30 minutes, the package's `approvals.ttl`. A card answered after that runs nothing, and its row reads refused. Writing a new message instead of answering leaves the task alone.

### Hand-test script

Start from `php artisan migrate:fresh --seed`, in demo mode. The replies below are the planner's. A real model shows the same cards and words its own replies.

1. Sign in as owner@example.com, open Board and click "Ask the assistant".
2. Click "Delete 'Order new office chairs'". A row "Searching tasks…" turns into "Searched tasks", and an amber card appears under it: "Waiting for your confirmation", "Delete this task? This cannot be undone.", then Task "Order new office chairs", Project "No project" and Assignee "Unassigned", with a red Confirm and a Decline. The turn is over and nothing is deleted: the card is still in To do, which counts 7.
3. Click Confirm. The card goes at once, a row "Deleting a task…" turns into "Deleted a task", and the reply is "Deleted “Order new office chairs”. It's off the board." The card leaves To do and the count drops to 6, with no page reload.
4. To send the same Confirm again, open the browser's Network tab, right-click the last `POST /acme/assistant` and choose Copy, then "Copy as fetch". Paste it into the console and run it. The new request in the Network tab answers 409 with "That confirmation is no longer waiting, so nothing ran again.", and nothing else changes.
5. Type `Delete 'Record a two-minute product tour'` and press Enter. A new card comes up. Click Decline. The row reads "Declined", the reply is "Okay, I left “Record a two-minute product tour” on the board.", and the card stays on the board. The model was told "The person declined this call, so it did not run. Do not try it again unless they ask."
6. Type `Delete 'Renew the SSL certificate'` and press Enter, then reload the page while the card shows. The panel comes back with your conversation and the card under your last message, rebuilt on the server from the task. The rows do not come back: rows are live only.
7. Double-click Confirm on the restored card. It runs once: the Network tab shows one `POST /acme/assistant`, the panel one "Deleted a task" row and the reply, and "Renew the SSL certificate" leaves Done. Reload: no card.
8. Type `Delete 'Cancel the Elm Street lease'`. That is a Globex task, so Acme's search does not find it and no card appears: "I couldn't find a task called “Cancel the Elm Street lease”."
9. Sign in as member@example.com and ask the Acme assistant `Delete 'Write the launch announcement'`. You get "Searched tasks" and no card, and the reply is "I can't delete tasks here. You're a Member in Acme, and I only get the tools your role allows, so “Write the launch announcement” is still on the board." viewer@example.com gets the same reply with "Viewer".

Run `php artisan migrate:fresh --seed` to put the boards back.

### What the tests cover

`tests/Feature/Board/AssistantApprovalTest.php` runs the delete through `POST /{team}/assistant` in demo mode, where a Confirm resumes on the same path as with a real model. It checks that the pause streams the card with the task's title, project and assignee, an input-less tool part and no delete; that the owner's Confirm deletes the task once, with a done row carrying `touches` and a reply in the same message; that Decline deletes nothing, shows the declined row and tells the model; that a second Confirm, and a Confirm after a Decline, answer 409 and run nothing; that a reload restores the waiting card and answering it works; that a member's and a viewer's assistant are never offered `delete-task`; that another Acme member, Globex's owner on Acme's endpoint and Globex's owner on Globex's endpoint cannot answer the owner's card, which still works for the owner afterwards; and that Acme's delete cannot reach a Globex task. One test sets a key and puts a model on the provider's gateway, to run the same pause and resume without the planner.

`tests/Feature/Board/BoardAssistantTest.php` checks which tools each role's assistant gets, including `delete-task` for an owner and an admin only, and only when the conversation is stored. `tests/Feature/Board/McpTest.php` still checks that MCP never lists or runs `delete-task`.

## Asking for missing details

Since stage 5, a task the assistant adds always has a priority and a due date, and since stage 6 so does one an MCP client adds. When the model's `create-task` call leaves them out, the call neither runs nor is refused: the panel shows a form the server built, with the title and the assignee already filled in, and the task is added only when you submit it, once, as you, with your values. This follows "Asking the person" in the package's `docs/asking.md`. What the page receives is MCP's form-mode elicitation, `{mode, message, requestedSchema}`, so any renderer of MCP forms could draw it.

What it takes in this app:

- `CreateTask` sets `$askForMissing = true` and names `priority` and `due_on` in `requiredForAgents()`. Models, the assistant's and MCP clients', are offered both as required and not nullable, a call without them is refused naming them or asks, and the form marks them required. The board's New task dialog, the JSON route and the CLI keep both optional. It is not an `agentSchema()`, because an action with one gets no generated route, and the dialog posts to `create-task`'s. Until stage 6 it was `rules()` keyed on `Surface::Agent` plus a `prepareForValidation()` that set a missing field to null, which held MCP clients to nothing and gave the form no required marker. MCP clients that show forms get this form too (see [Asking over MCP](#asking-over-mcp)).
- `ask()` builds what the form says beyond the schema: the sentence "Give this task a due date and a priority, and I'll add it.", the title, the priority and the assignee always shown for review, the team's members to pick from by full name, the priorities with their labels, and Normal as the default priority. It reads only this team, from the context alone, and never sees the model's arguments.
- The form's labels are the schema's titles (`due_on` is titled "Due date"), and its help lines are the fields' descriptions, which `CreateTask::schema()` words for a person as well as a model. `lang/en/validation.php` calls `due_on` "due date" in error messages.
- A field opens on the model's value when validation accepted it, so "Marcus Reed" opens selected. A model names him in full because `BoardAssistant`'s instructions list the team's members; the planner does the same.
- The panel renders `elicitation()` of the newest message with the package's `<ElicitationForm>`, styled with the kit's shadcn/ui classes through `classNames` (`resources/js/components/assistant/assistant-form.tsx`), and answers with `answerElicitation(chat, options, id, result)`. That checks a Submit first, with a `Precognition: true` request to the same endpoint, so an answer the action's rules refuse shows its errors under the fields before anything runs. Then it hands the answer to the chat, which sends it as it sends a Confirm.
- The model reads "The person filled in: title, priority, due_on, assignee. Done." and never the values: not in its next step, and not in the conversation laravel/ai stores, where the call keeps the model's own arguments. That is why the planner's reply names no title or date: you may have changed any of them.
- A form waits as a confirmation card does: in the cache store for 30 minutes (`approvals.ttl`), answered only from your own session, in the team you were asked in, once. A reload restores it, rebuilt on the server, without what you typed.

### Hand-test script

Start from `php artisan migrate:fresh --seed`, in demo mode. The replies below are the planner's.

1. Sign in as owner@example.com, open Board and click "Ask the assistant".
2. Click "Add a task for Marcus to review the pricing page". No row runs: the call waits, and a form appears under your message. It reads "Asked by Agentic Actions Demo", then "Give this task a due date and a priority, and I'll add it.", with Title "Review the pricing page", Priority "Normal" and an empty Due date, all three starred, and Assignee "Marcus Reed", each with a help line, then Submit, Decline and Not now. Nothing is added yet: To do counts 7.
3. Click Submit with the due date empty. The browser stops it before any request: Due date shows "Please fill out this field.", and the Network tab shows nothing new. The server checks the same rule for an answer that skips the browser, with 422 and "The due date field is required." (the tests send one).
4. Pick a due date and High, and click Submit. The form goes, a row "Creating a task…" turns into "Created a task", and the reply is "Done. I added the task with the details you filled in, and it's in To do." A "Review the pricing page" card lands in To do, high, due that day and assigned to Marcus Reed, and the count goes to 8.
5. Copy that last `POST /acme/assistant` (the one without `Precognition`) as fetch and run it in the console. It answers 409 with "That confirmation is no longer waiting, so nothing ran again.", and no second task appears.
6. Click the chip again, then Decline. The row reads "Declined", the reply is "Okay, I didn't add it.", and To do still counts 8.
7. Click the chip again, pick a due date and reload the page. The form comes back under your last message, rebuilt on the server, with the due date empty: what you type is never stored.
8. Click Not now. The row reads "Declined" too, and the reply is "No problem, I left it for now. Ask me again when you have the details." The model was told you closed the form, not that you declined it.
9. Click "Add three launch tasks for Marcus". Three tasks land with no form: the planner gives each a priority and a due date, so the calls are complete.
10. Make the window phone-narrow, or switch the appearance to dark in Settings, and ask again: the form fills the panel's width, and its date picker and selects follow the theme.
11. Sign in as viewer@example.com and click the chip. No form and no row: a viewer's assistant has no `create-task`, and the reply says so.

Run `php artisan migrate:fresh --seed` to put the boards back.

### What the tests cover

`tests/Feature/Board/AssistantFormTest.php` runs the ask through `POST /{team}/assistant` in demo mode, where a Submit resumes on the same path as with a real model. It checks that the pause streams the form with its exact params and labels, the title, priority and due date required, an input-less tool part, no row and no task; that a Precognition check answers 204, and the answer then adds the task once with the person's title, priority, due date and assignee, a done row and the reply in the same message; that the model read only "The person filled in: title, priority, due_on, assignee. Done.", and that neither its steps nor laravel/ai's stored messages hold the typed title or date; that a second answer gets 409; that answers the action's rules refuse (an unknown priority, a date that is not one, an empty due date) get 422 by field and add nothing, while the form still takes a good answer; that Decline and Not now add nothing and tell the model which; that another Acme member, Marcus, Globex's owner on Acme's endpoint and on Globex's, and Marcus on Globex's endpoint cannot answer the owner's form, which still works for the owner afterwards; that a reload restores the form and answering it works; that "Add three launch tasks for Marcus" runs with no form; and, with a key set and a model on the provider's gateway, the same pause and resume without the planner.

`TaskActionsTest.php` and `ActionsCliTest.php` still create tasks without a priority or a due date through the board's route and the CLI: `requiredForAgents()` reaches neither. `McpFormTest.php` covers the same form over MCP.

## Tables and charts

Since stage 8 (package 0.9), the assistant answers questions about the board with a table the person sees and a chart the page draws. Three Read actions in `app/Actions/Reports/` implement the package's `ShowsTable`: `tasks-by-status` (each column's tasks and overdue tasks), `tasks-by-assignee` (each person's open and done tasks and the share done, busiest first) and `completed-per-day` (the tasks completed each day, 14 days by default, quiet days as 0). This follows "Tables" in the package's `docs/data.md`.

What it takes in this app:

- Each action declares `columns()` with the package's `Column` types and returns its rows: a list, or for `tasks-by-assignee` a grouped query the package limits. The columns are its output on every surface: the JSON route, `actions:run` (which prints a table), MCP and the copilot.
- In the copilot, each table arrives as a `data-view` part right after its row. `resources/js/components/assistant/assistant-table.tsx` draws it: the chart the rows' shape calls for (bars after a text column, a line after a date column, numbers for a single row), with shadcn/ui's chart and Recharts, then the package's `<ActionTable>`, styled with the kit's classes, with the time the rows were read and Refresh.
- The model reads a short copy: "The person now sees this as a table of 3 rows.", the first rows, and "say what stands out; do not repeat the rows". The numbers you read are the query's, never the model's retyping.
- Each table is kept with the conversation in the package's `agentic_views` table (`php artisan actions:install --copilot` published its migration), so a reload shows it again under the words that describe it. `routes/console.php` prunes the tables of deleted conversations daily.
- Refresh posts to `/{team}/actions/_views/{ref}`: the action runs again as you, with every check a call to its route gets. The chart follows the fresh rows. A reload still shows the table the model described.

### Hand-test script

In demo mode, the replies below are the planner's.

1. Sign in as owner@example.com, open Board and click "Ask the assistant".
2. Click "Tasks by status". A row "Counting the tasks per column…" turns into "Counted the tasks per column", then a bar chart of To do, Doing and Done, with the overdue tasks beside each, and the table under it: Column, Tasks, Overdue (hover Overdue for its description). The reply is one sentence, "To do holds the most tasks, 7, and 2 tasks are overdue.", and never repeats the rows.
3. Click "Who has the most tasks?". A bar chart of open and done tasks per person, and a table with Done share as a percentage.
4. Click "Tasks completed per day". A line chart over the last 14 days, quiet days at 0.
5. Move a task to Done on the board, then click Refresh under the columns table. The table and the chart show the new numbers, and the time under the table changes.
6. Reload the page. The three tables come back under their questions, as they were first shown: the refresh did not change what the model described.
7. Run `php artisan actions:run tasks-by-status --as=1 --tenant=acme`: the same rows, printed as a table.
8. Sign in as viewer@example.com and click "Tasks by status": a viewer reads the board, so the table shows too.

### What the tests cover

`tests/Feature/Board/ReportTablesTest.php` checks each action's output through its JSON route (the columns, the rows, the chart, Globex's tasks never counted, the `days` bounds, an outsider refused), and one copilot turn in demo mode: the `data-view` part right after the done row, the planner's sentence, the kept table, the reload that returns it before the words, and a refresh.

## Datasets

Since stage 9 (package 0.9), the assistant also asks questions of its own. Two datasets in `app/Actions/Reports/` declare what can be counted over the team's tasks and how it can be split. This follows "Datasets" in the package's `docs/data.md`.

| Dataset            | Its time                                                          | Split and filter by                 | Measures                |
| ------------------ | ----------------------------------------------------------------- | ----------------------------------- | ----------------------- |
| `task-stats`       | when a task was added                                             | column, priority, assignee, project | tasks, done, done share |
| `completion-stats` | when a task was completed, and `scope()` keeps only the done ones | priority, assignee, project         | completed tasks         |

- **The call is made only of these names.** For example: `{"measures": ["tasks"], "by": ["assignee"], "filters": [{"dimension": "priority", "values": ["high"]}]}`. The package writes the query inside the team, and the model never writes SQL. A name the dataset does not declare is refused before any query runs.
- **Assignees are shared.** Inside a team, the package reads each related row in the team's scope too, so a task linked to another team's project would show no project. Users belong to no one team, and `TeamScope` throws for them, so both datasets name `assignee` in `$shared`.
- **With no dates in the call, both count the last twelve months** (`$range = '-12m'`), so "tasks per project" matches the whole board. The caption above each table says what was asked and for which dates.
- **The answer is a table like any other.** It gets a line chart with a grain and bars with a dimension, is kept with the conversation, and has Refresh.
    - A percentage, such as the done share, appears in the table but is never drawn beside a count.
    - A row with no value, such as tasks with no project, has an empty cell and a bar under a dash.
- **The seeder spreads the dates.** It adds each team's tasks three days apart, the last one today, and a done task is completed two days after it was added. So after `php artisan migrate:fresh --seed` the questions over time have weeks to count. On a database seeded before stage 9, every seeded task was added on the day of the seeding.
- **`actions:check` warns that SQLite sets no statement time limit.** That is expected here; the package's caps still bound every answer.

### Hand-test script

1. Sign in as owner@example.com, open Board and click "Ask the assistant".
2. Click "Tasks per project". The row "Counting the tasks…" turns into "Counted the tasks". Then come bars per project and the table: Project, Tasks, and Done share as a percentage. Its caption reads "Tasks, Done share by Project · 1 Oct 2025 – 30 Sep 2026 · the 50 highest by Tasks", with the dates following today. On a freshly seeded board the reply is "Website relaunch has the most tasks, 5, and 40% of them are done."
3. Click "Tasks completed per week". A line runs over the last eight weeks, with quiet weeks at 0, and the reply starts "3 tasks were completed in these 8 weeks".
4. Type "High-priority tasks per person". The table lists each person's high-priority tasks, and the caption ends "Priority is High · the 50 highest by Tasks".
5. With `OPENROUTER_API_KEY` set, ask questions no script knows. The model picks `task-stats` or `completion-stats` and composes the call, and the caption shows what it asked. Try:
    - "How many tasks did we add each week this month, compared with last month?"
    - "Which project has the most high-priority tasks still to do?"
    - "Who completed the most high-priority tasks?"
6. Ask Claude the same questions over MCP (see [MCP](#mcp)). It calls the same datasets, gets the table as JSON with its caption, and writes its own table from it.
7. Run `php artisan actions:run task-stats --as=1 --tenant=acme --input='{"measures": ["tasks", "done_share"], "by": ["project"]}'`. It prints the caption, then the same rows.

### What the tests cover

`tests/Feature/Board/DatasetTablesTest.php` asks each dataset through its JSON route, on a board fixed at 30 Sep 2026:

- tasks and the done share per project;
- high-priority tasks per person;
- completions per week, leaving out a task in Doing that still carries a completion time (`scope()`);
- Globex's tasks, which are never counted;
- the captions and the charts;
- refusals: an undeclared name, an unknown priority, and an outsider.

It also runs the three scripted questions in demo mode.
