# Agentic Actions demo

A team task board on the Laravel React starter kit (Inertia 3, React 19, teams) that shows the
[Agentic Actions](https://github.com/agentic-actions/laravel) package. Every board operation is one Action class, and
the same class answers the board's forms, JSON routes, `php artisan actions:run`, the copilot's tool calls and MCP
clients. Teams are the tenants. Laravel 13, PHP 8.4, SQLite. It runs publicly at https://demo.agentic-actions.com in
hosted mode (below); `README.md` covers setup and hosting, and `DEMO.md` has the hand-test scripts.

The package comes from Packagist (`agentic-actions/laravel`), and the npm client is
`file:vendor/agentic-actions/laravel/js`: it installs from the Composer package, so keep that dependency as it is and run
`composer install` before `npm install`. The package's documentation is at https://agentic-actions.com.

## Structure

| Path                                                     | What is there                                                                    |
| -------------------------------------------------------- | -------------------------------------------------------------------------------- |
| `app/Actions/Tasks`, `Projects`, `Reports`               | The board's actions; business logic lives here, never in a controller            |
| `app/Actions/Teams`, `app/Actions/Demo`                  | The starter kit's team actions, and the hosted demo's sandbox actions            |
| `app/Ai`                                                 | The copilot agent and `ScriptedPlanner`                                          |
| `app/Http/Controllers`, `app/Http/Middleware`            | Thin controllers; the hosted demo's guards                                       |
| `app/Console/Commands`                                   | `app:tunnel`, `demo:prune-sandboxes`, `demo:prune-clients`                       |
| `config/agentic-actions.php`, `config/demo.php`          | The package's wiring; the hosted demo's switch and caps                          |
| `resources/js/pages`, `resources/js/components`          | Inertia pages; the board, the assistant panel and the sandbox bar                |
| `routes/web.php`, `routes/settings.php`, `routes/ai.php` | The board, the assistant and `/demo`; settings and tokens; OAuth for MCP clients |
| `tests/Feature/Board`, `Teams`, `Demo`, `tests/Support`  | Every surface through the real kernel; `Boards::make()` and `Sandboxes::start()` |

## How it uses the package, file by file

| File                                                                              | What it does with the package                                                                                                                                                                                                                   |
| --------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `config/agentic-actions.php`                                                      | Tenant model `Team`, parameter `current_team` (by slug), `TeamMembership`, `TeamScope`, MCP at `mcp/t/{current_team}`                                                                                                                           |
| `app/Tenancy/TeamMembership.php`, `TeamScope.php`                                 | The membership check and the scope `$context->find()` uses, so another team's task is "Not found."                                                                                                                                              |
| `app/Actions/Tasks/*`, `app/Actions/Projects/CreateProject.php`                   | The eight actions (`#[Expose]`, `schema()`, `authorize()`, `$touches`); `DeleteTask` is Destructive and needs a confirmation; `CreateTask` asks (below)                                                                                         |
| `app/Actions/Reports/*`, `resources/js/components/assistant/assistant-table.tsx`  | Tables and datasets (package 0.9): three Read actions with `ShowsTable` and `Column`, two datasets (`TaskStats`, `CompletionStats`) with `Dimension` and `Measure`; in the panel, `viewsOf()`, `<ActionTable>` and a shadcn/ui chart (Recharts) |
| `app/Actions/Concerns/InteractsWithBoard.php`                                     | Shared helpers: the team from the context, the role check, names to rows of this team                                                                                                                                                           |
| `routes/web.php`                                                                  | `Actions::routes(tenant: true)` inside the kit's `{current_team}` group, plus the assistant's two routes                                                                                                                                        |
| `app/Http/Controllers/BoardController.php`                                        | Runs `ListTasks` and `SummarizeBoard` in-process for the page                                                                                                                                                                                   |
| `app/Ai/Agents/BoardAssistant.php`                                                | The copilot: a laravel/ai agent with `InteractsWithActions`, `#[UseToolset]`, `#[WithPageContext]`, `RemembersConversations`                                                                                                                    |
| `app/Http/Controllers/AssistantController.php`                                    | `ChatRequest::from()->respond()`, `ActionsProtocol`, `Transcript::forUseChat()`, and `Actions::conversation()` for one conversation per user and team                                                                                           |
| `database/migrations/*_create_agentic_conversations_table.php`                    | The package's conversation store, published by `actions:install` (tag `agentic-actions-migrations`)                                                                                                                                             |
| `app/Ai/ScriptedPlanner.php`                                                      | Demo mode: plays the model on laravel/ai's fake gateway when the key is empty                                                                                                                                                                   |
| `app/Http/Controllers/Teams/TeamTokenController.php`, `app/Enums/TokenAccess.php` | Sanctum tokens for MCP with `actions:read`, `actions:write` and `tenant:{id}` abilities                                                                                                                                                         |
| `config/auth.php` (`api`, Passport), `routes/ai.php`, `config/mcp.php`            | OAuth for remote MCP clients (package 0.7): the Passport guard named in `mcp.middleware`, `Mcp::oauthRoutes()`, the redirect allowlist                                                                                                          |
| `app/Providers/AppServiceProvider.php`, `resources/js/pages/oauth/consent.tsx`    | One-hour access tokens, 30-day refresh tokens, and the consent page as an Inertia page from `Consent::from()` (native forms post Allow and Deny)                                                                                                |
| `app/Http/Controllers/Teams/TeamConnectionController.php`                         | "Connected apps" on the AI clients page: `McpConnection::for()` and `revoke()`; leaving a team, or being removed, revokes too                                                                                                                   |
| `app/Http/Middleware/UsePublicUrl.php`, `app/Console/Commands/Tunnel.php`         | `APP_PUBLIC_URL` (URLs on the tunnel's host, no debug page, for requests through it) and `php artisan app:tunnel`                                                                                                                               |
| `resources/js/agentic/actions.ts`                                                 | Typed definitions, generated by `php artisan actions:typescript`                                                                                                                                                                                |
| `resources/js/components/board/*`                                                 | `useAction()`, `callAction()`; `board-sync.tsx` is the one `useActionSync()` (assistant rows and the change feed)                                                                                                                               |
| `resources/js/components/assistant/*`                                             | `useChat` with `actionsChat()`, `messageSegments()`, `<ActionActivity>`, `<ApprovalCard>`, `<ElicitationForm>` (`assistant-form.tsx`), Markdown                                                                                                 |
| `actions.exposure.json`                                                           | The reviewed exposure snapshot that `actions:check` compares against                                                                                                                                                                            |
| `tests/Feature/Board/*`, `tests/Support/Boards.php`                               | Every surface through the real kernel; `Boards::make()` builds Acme and Globex with one user per role                                                                                                                                           |

The tables the features need are all committed: laravel/ai's `agent_conversations`, the package's
`agentic_conversations`, `agentic_views` and `agentic_mcp_connections`, Sanctum's `personal_access_tokens`, Passport's `oauth_*`.
Passport's keys are not: `php artisan passport:keys` writes them to `storage/` (git-ignored). Without them the MCP URL
answers 500 instead of 401 and `actions:check` fails. The suite never reads them: `tests/TestCase.php` gives Passport a
key pair of its own. `php artisan actions:install --no-interaction --web
--copilot --approvals --mcp --tenancy` is what publishes them in a new app; here it reports each one "ALREADY THERE".

## Hosted mode

`DEMO_HOSTED=true` (`config('demo.hosted')`) turns on the public demo. Off by default, so a local copy behaves as the
starter kit does, with the seeded users. Read it per request, never at route registration: tests switch it with
`config(['demo.hosted' => true])`, and Wayfinder must see the same routes in every environment.

- **Sandboxes.** `POST /demo` runs `App\Actions\Demo\CreateSandbox`: a `demo_sandboxes` row, the visitor ("You", Acme
  owner) plus Marcus, Vera and Gus with random passwords, Acme and Globex with random slug suffixes, and the sample board
  from `SampleBoard` (which `DatabaseSeeder` also uses). People and teams carry `demo_sandbox_id`; teams a visitor adds
  take it from their creator (`CreateTeam`). `GET /demo/resume/{sandbox}` is a signed link back in; `POST
/demo/as/{persona}` is "View as", only within one sandbox. The shared Inertia prop `demo` carries `hosted`,
  `expires_at`, `resume_url` and `personas`.
- **Expiry.** 24 hours. At expiry `EndExpiredSandbox` (web group and MCP middleware) signs the sandbox's people out
  and refuses its tokens. `demo:prune-sandboxes` (every 15 minutes) runs `DeleteSandbox` five minutes later, which
  deletes every row keyed to the sandbox's people and teams, including tokens, OAuth grants, connections,
  conversations and sessions. A new table keyed by a user or team id needs a line there and in `SandboxPurgeTest`.
- **Kit features.** `DisabledOnHostedDemo` answers 404 for sign-up, password reset, verification, two-factor, passkeys
  and password confirmation, and 403 with a line for email changes, password changes, account deletion and invitations.
  The pages hide those controls from the `demo` prop. `TrustHostedDemoHost` answers 400 to a host other than
  `APP_URL`'s.
- **Scripted copilot.** `config('ai.scripted')` is forced on, the OpenRouter key reads null, and
  `Http::preventStrayRequests()` refuses outbound calls. Never add a code path that reaches a model on the host.
- **Limits.** Named limiters in `AppServiceProvider::configureRateLimits`, each keyed on its own; caps in
  `config/demo.php` (`InteractsWithBoard::ensureRoomFor()` for tasks and projects, `TeamTokenController` for tokens).
  A new route that writes needs a limiter.

## Commands

```bash
php artisan test --compact                 # Pest, on an in-memory database; add a file or --filter
vendor/bin/pint --dirty --format agent     # after any PHP change
composer types:check                       # PHPStan, level 7 (run with LC_ALL=C under a non-English locale)
npm run check && npm run types:check       # Vite+ lint and format, then tsc
npm test                                   # the frontend's tests, such as the Markdown renderer against hostile replies
npm run build                              # after a frontend change; never leave a dev server or public/hot behind
php artisan actions:check                  # "Every check passed."; --update rewrites actions.exposure.json
php artisan actions:typescript             # regenerates resources/js/agentic/actions.ts
php artisan actions:list
php artisan actions:run summarize-board --as=1 --tenant=acme
```

Before a commit, all of these pass: the test suite, PHPStan with 0 errors, Pint, `npm run check`, `tsc` and
`actions:check`. Change `actions.exposure.json` (`actions:check --update`) and `actions.ts` only when an action's
exposure changes on purpose, and say so in the commit. Stage files by path.

## Conventions

- Business logic goes in Action classes. Actions read the person and the team from their context, authorize and
  validate themselves, and return values, not responses. Controllers stay thin.
- PHP: explicit parameter and return types, PHPDoc blocks with array shapes rather than inline comments, curly braces
  on every control structure, constructor property promotion. Match the sibling files.
- Copy a person reads (refusals, toasts, form help, the copilot's scripted replies): plain, short sentences. A field's
  description in `schema()` is also its help line in a form.
- Tests are Pest feature tests through the real kernel, with factories and `tests/Support` builders. Never call a real
  model: `phpunit.xml` forces `OPENROUTER_API_KEY` empty and `DEMO_HOSTED` off.
- Migrations are additive, with a working `down()`.
- Never commit secrets, `.env`, `storage/*.key` or a database file.

## Running locally

The seeded users (password `password`, ids 1 owner, 2 member, 3 viewer, 4 globex for `--as`) are for local use.
`php artisan migrate:fresh --seed` resets the boards. With `OPENROUTER_API_KEY` empty the copilot runs scripted
(`ScriptedPlanner`: "What's overdue?", "Add three launch tasks for Marcus", "Add a task for Marcus to review the pricing
page", "Delete '…'", "Tasks by status" and more); with a key it calls OpenRouter with `OPENROUTER_MODEL`.
`php artisan app:tunnel` opens a public address for a Claude custom connector and refuses while any account still has
the password `password`.

The first `composer types:check` in a new copy, with no result cache yet, may need more memory than PHP's default;
the script already passes `--memory-limit=1G`.

## Asking for missing details

`CreateTask` has `$askForMissing`: a model's call that leaves out the priority or the due date pauses on a form built
by `ask()` (MCP's form elicitation), and the task is added when the person submits it. The requirement is models' only,
the copilot's and MCP clients' alike, through `requiredForAgents()` (`['priority', 'due_on']`, package 0.6): they are
offered both as required, and the form marks them required, so the browser stops an empty Submit before any request.
The board's dialog, the JSON route and the CLI keep both optional. Keep it there: an `agentSchema()` would remove the
generated `create-task` route the board's dialog posts to, and the old `rules()` keyed on `Surface::Agent` plus a
null-filling `prepareForValidation()` gave the form no required marker. The model reads only the names of the fields
filled, so a test asserts the typed values reach neither the model nor `agent_conversation_messages`
(`tests/Feature/Board/AssistantFormTest.php`). A field's description is its help line in the form, so describe what a
form may show for a person as well as a model.

## MCP

Tokens come from each team's "AI clients" page, `/settings/teams/{team}/tokens`. The MCP URL is
`/mcp/t/{team slug}`. No token is ever offered `delete-task`. A `create-task` call without a priority or a due date asks
for them when the client is on protocol 2026-07-28 and declares form elicitation (an `InputRequiredResult`; its retry
adds the task), and every other client gets the refusal naming both (`tests/Feature/Board/McpFormTest.php`). As of 26
Sept 2026 only the official TypeScript client 2.x with a form handler and the Inspector's web page show the form; the
Inspector's CLI and laravel/mcp 1.0.1's `Client` get the refusal (DEMO.md, "Asking over MCP"). A request state is not
spent by its retry: the same retry sent twice adds two tasks.

**OAuth (package 0.7).** A Claude custom connector, Claude Desktop's "Add custom connector" or ChatGPT signs in with
OAuth on Passport: `mcp.middleware` is `auth:sanctum,api` (Sanctum first, or Sanctum tokens answer 401), and the
person approves one team's URL on `oauth/consent`. The token reaches only that URL, with the person's role, for an
hour, and refreshes for 30 days. Revoke is on the AI clients page under "Connected apps". Such a client needs a public
HTTPS address. The host sets `APP_PUBLIC_URL` to its own URL. On a local copy, `php artisan app:tunnel` opens a
Cloudflare quick tunnel to the Herd site, sets `APP_PUBLIC_URL` while it runs and clears it after (DEMO.md, "Connect
Claude.ai (custom connector)"). On a secured Herd site the URLs already
follow the tunnel's host, since Herd copies `X-Forwarded-Host` into the host; `APP_PUBLIC_URL` adds the connector URL
on the AI clients page, https on an http origin, the same URLs off Herd, and no debug page for tunnel requests. Tests:
`tests/Feature/Teams/OAuthConsentTest.php` and `TeamConnectionTest.php` (a Claude-shaped client in
`tests/Support/OAuthClient.php`), `tests/Feature/PublicUrlTest.php`, `tests/Feature/TunnelCommandTest.php`.
