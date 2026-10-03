# Agentic Actions demo

A team task board that shows [Agentic Actions for Laravel](https://github.com/agentic-actions/laravel) in a real app. Each operation on the board is one Action class. The same class answers the board's forms, a JSON route, `php artisan actions:run`, a copilot's tool calls and MCP clients such as Claude. Teams are the tenants: every action runs inside the team in the URL, and each role gets a different answer.

Try it at **https://demo.agentic-actions.com**. "Try the demo" gives you a private sandbox for 24 hours, with no sign-up. The package's documentation is at https://agentic-actions.com.

It is built on the Laravel React starter kit with teams: Laravel 13, Inertia 3, React 19, PHP 8.4 and SQLite. [DEMO.md](DEMO.md) has the hand-test scripts for every feature, and [CLAUDE.md](CLAUDE.md) is the guide for contributors and coding agents.

## What to try

1. Open the board. The cards and the counts come from the `list-tasks` and `summarize-board` actions, run in-process.
2. Change a card's status, or add a task. The board's forms call the same actions through their JSON routes, and Precognition checks the fields against the action's `schema()` as you type.
3. Click **Ask the assistant** and try the suggestion chips. "Add three launch tasks for Marcus" runs real actions, and the cards appear on the board. "Delete 'Order new office chairs'" asks you to confirm on a card first. "Add a task for Marcus to review the pricing page" opens a form for the due date and the priority. "Tasks by status" answers with a table and a chart.
4. Switch to Vera, a viewer. The same buttons are there, and the server refuses her writes. Gus is not in Acme, so the Acme board answers 403 for him.
5. Open the team's **AI clients** page. Make a token for Claude Code or Cursor, or copy the connector URL for a Claude.ai custom connector. Your own model then works on the same board over MCP, with your role.

## Run it locally

You need PHP 8.4 with `pdo_sqlite`, Composer, and Node 22.18 or later.

```bash
git clone https://github.com/agentic-actions/demo.git agentic-actions-demo
cd agentic-actions-demo
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan passport:keys
npm run build
```

[Laravel Herd](https://herd.laravel.com) serves the folder at http://agentic-actions-demo.test (run `herd link` in it if the folder is outside a parked path). Without Herd, set `APP_URL=http://127.0.0.1:8000` in `.env` and run `php artisan serve`.

`passport:keys` writes the OAuth key pair to `storage/`, which git ignores. Without it, the MCP URLs answer 500 instead of 401. The npm client `@agentic-actions/client` installs from the Composer package (`file:vendor/agentic-actions/laravel/js`), so run `composer install` before `npm install`.

### Seeded users

Every password is `password`. The seeded users are for local use only; the hosted demo makes a sandbox for each visitor instead.

| Email              | Name        | Acme   | Globex |
| ------------------ | ----------- | ------ | ------ |
| owner@example.com  | Olivia Park | Owner  |        |
| member@example.com | Marcus Reed | Member | Member |
| viewer@example.com | Vera Lind   | Viewer |        |
| globex@example.com | Gus Novak   |        | Owner  |

`php artisan migrate:fresh --seed` puts the boards back to the seeded state.

What each role may do on a board:

|                         | Owner | Admin | Member | Viewer |
| ----------------------- | ----- | ----- | ------ | ------ |
| Read, search, summarize | yes   | yes   | yes    | yes    |
| Create and update tasks | yes   | yes   | yes    | no     |
| Delete tasks            | yes   | yes   | no     | no     |
| Create projects         | yes   | yes   | no     | no     |

### The copilot

With `OPENROUTER_API_KEY` empty, the copilot runs in scripted mode. `app/Ai/ScriptedPlanner.php` plays the model on laravel/ai's fake gateway, and the actions, permissions, cards and forms it drives are real. Set the key (and `OPENROUTER_MODEL`, `anthropic/claude-sonnet-5` by default) to use a real model. `ASSISTANT_SCRIPTED=true` keeps scripted mode with a key set.

### Claude.ai and other OAuth clients

A Claude.ai custom connector or ChatGPT signs in with OAuth and needs a public HTTPS address. `php artisan app:tunnel` opens a short-lived Cloudflare quick tunnel to a local copy. It refuses to start while any account still has the password `password`. See [DEMO.md](DEMO.md#connect-claudeai-custom-connector).

### The CLI

`--as` takes a user id and `--tenant` a team slug. The seeded ids are 1 owner, 2 member, 3 viewer and 4 globex.

```bash
php artisan actions:list
php artisan actions:run summarize-board --as=1 --tenant=acme
php artisan actions:run create-task title="Call the printer" assignee="Marcus Reed" priority=high --as=1 --tenant=acme
php artisan actions:run create-task title="Nope" --as=3 --tenant=acme       # viewer: exit 3, denied
php artisan actions:check
```

### Tests

```bash
php artisan test --compact
npm test
```

## How hosted mode works

`DEMO_HOSTED=true` turns the app into the public demo. It is off by default, and a local copy behaves as described above.

**A sandbox per visitor.** "Try the demo" (`POST /demo`) creates a sandbox: you as the Acme owner, plus Marcus, Vera and Gus, the Acme and Globex boards, and a personal team for each person. Every person in it has a random password nobody knows. You are signed in straight away. The bar at the top shows the time left and has a "View as" menu that signs you in as one of the other three. The same menu copies a signed resume link, also shown on the AI clients page, that signs you back in from another browser, which is how you approve a Claude.ai connector on another device. Your session lasts as long as the sandbox. Team slugs get a random suffix, such as `acme-k3x9qp`, and so do the teams you add or rename.

**A scripted copilot.** On the host the copilot always follows the script, whatever `ASSISTANT_SCRIPTED` or any key says, and the app refuses every outbound HTTP request. To use a real model on the board, connect your own MCP client.

**Kit features off.** Sign-up, password reset, email verification, two-factor and passkeys answer 404. Email changes, password changes, account deletion and invitations answer 403, and their controls are hidden. Nothing sends email. The app answers only to the host in `APP_URL`.

**Limits.** Each surface has its own named rate limit (`AppServiceProvider::configureRateLimits`):

| Surface                                    | Limit                                                                                                                                             |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Try the demo                               | 5 an hour per address, 120 an hour in all, at most 500 live sandboxes                                                                             |
| Resume link and View as                    | 30 a minute per address                                                                                                                           |
| Assistant turns                            | 10 a minute and 300 a day per person, 30 a minute per address, one reply at a time                                                                |
| Board actions                              | 60 calls and 30 change-feed polls a minute per person                                                                                             |
| Team, member, token and connection changes | 20 a minute per person; 5 new tokens a minute                                                                                                     |
| MCP                                        | 30 calls a minute per person (60 on a local copy)                                                                                                 |
| OAuth                                      | registration 10 a minute and 30 a day per address, 500 a day in all; Anthropic's range 120 a minute and 500 a day of its own; consent 20 a minute |

A sandbox team holds at most 200 tasks and 20 projects, a visitor can add 5 teams, and each person can hold 5 live tokens per team. A token expires with its sandbox.

**Resets.** When a sandbox expires, its people are signed out and its MCP tokens stop working. The scheduler runs `demo:prune-sandboxes` every 15 minutes. It deletes each sandbox that expired more than five minutes ago, with everything in it: people, teams, boards, conversations, sessions, Sanctum tokens, OAuth tokens and MCP connections. `demo:prune-clients` deletes, once a day, OAuth clients that registered and never connected. `passport:purge` and `sanctum:prune-expired` run daily too, and with the `database` cache store, a daily task deletes its expired rows.

## Hosting it

These are the steps for a Laravel Forge server with PHP 8.4, nginx, Node 22.18 or later, and optionally Redis. The server's default command-line PHP must be 8.4 too (in Forge, the server's PHP tab): `npm run build` runs `php artisan` through whatever `php` comes first on the path, not `$FORGE_PHP`.

### Site and DNS

- Create the site for `demo.agentic-actions.com` with zero-downtime deployments, and add a Let's Encrypt certificate.
- Point an `A` record at the server, DNS only. With Cloudflare's proxy off, the app sees the real client address for its rate limits, and Anthropic's addresses (`160.79.104.0/21`) reach the OAuth routes without Cloudflare's firewall in the way. If you ever turn the proxy on, add `$middleware->trustProxies(at: [...])` with Cloudflare's ranges in `bootstrap/app.php`.

### Environment

Set these in the site's `.env`. Never set `OPENROUTER_API_KEY` or any other model key on the host: not in `.env`, the PHP-FPM pool or the system environment.

| Variable                   | Value                                                               |
| -------------------------- | ------------------------------------------------------------------- |
| `APP_NAME`                 | `"Agentic Actions Demo"`                                            |
| `APP_ENV`                  | `production`                                                        |
| `APP_KEY`                  | Made once, before the first deploy, and never changed (below)       |
| `APP_DEBUG`                | `false`                                                             |
| `APP_URL`                  | `https://demo.agentic-actions.com`                                  |
| `APP_PUBLIC_URL`           | `https://demo.agentic-actions.com` (shows the connector URL)        |
| `VITE_APP_NAME`            | `"${APP_NAME}"`. `npm run build` reads it for the page titles       |
| `DEMO_HOSTED`              | `true`                                                              |
| `ASSISTANT_SCRIPTED`       | `true`                                                              |
| `ASSISTANT_DEMO_PACE_MS`   | `150`                                                               |
| `LOG_CHANNEL`              | `daily`                                                             |
| `LOG_LEVEL`                | `warning`                                                           |
| `DB_CONNECTION`            | `sqlite`                                                            |
| `DB_DATABASE`              | The absolute path of the SQLite file, outside the release directory |
| `DB_BUSY_TIMEOUT`          | `5000`                                                              |
| `DB_JOURNAL_MODE`          | `wal`                                                               |
| `DB_SYNCHRONOUS`           | `normal`                                                            |
| `DB_TRANSACTION_MODE`      | `IMMEDIATE`                                                         |
| `CACHE_STORE`              | `redis`, or `database` without Redis                                |
| `SESSION_DRIVER`           | `redis`, or `database` without Redis                                |
| `SESSION_LIFETIME`         | `1440`, the sandbox's 24 hours. Hosted mode never allows less       |
| `SESSION_SECURE_COOKIE`    | `true`                                                              |
| `REDIS_HOST`               | `127.0.0.1`, when Redis is used                                     |
| `QUEUE_CONNECTION`         | `sync`. Nothing is queued, so no worker is needed                   |
| `MAIL_MAILER`              | `array`. Nothing sends mail                                         |
| `INERTIA_SSR_ENABLED`      | `false`                                                             |
| `INERTIA_DEVTOOLS_ENABLED` | `false`                                                             |

Make the app key before the first deploy, since `php artisan key:generate` needs `vendor/`, which a new site does not have yet:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
```

The deploy caches the config, so a later change to `.env` takes effect only after `php artisan optimize` in the current release, or the next deploy.

Redis takes the rate-limit counters, the board's polling and the sessions off SQLite, but either store works. With the `database` store, the `cache` and `sessions` tables come from the migrations.

### Shared files

A zero-downtime release is a new directory each time, so these live outside it:

- `.env`, which Forge shares between releases.
- `storage/`, which Forge also shares. Passport's key pair lives there. Make it once, from the current release, after the first deploy, and never again: new keys end every connector's tokens.

    ```bash
    php artisan passport:keys
    chmod 600 storage/oauth-private.key storage/oauth-public.key
    ```

- The SQLite file. Create it once, for example `touch /home/forge/demo.agentic-actions.com/database.sqlite`, and set `DB_DATABASE` to that path. Never copy a local database to the host.

### Deploy script

```bash
$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan optimize

$ACTIVATE_RELEASE()
```

Composer comes first: the npm client installs from `vendor/`, and the build runs `php artisan` to write the route helpers, so `vendor/` and `.env` must exist before `npm run build`. The build also fetches fonts over HTTPS. `.npmrc` turns off npm's install scripts.

### Scheduler and workers

- Turn on Forge's scheduler (`php artisan schedule:run` every minute). It runs the sandbox clean-up and the daily prunes. `withoutOverlapping` needs a shared cache, which both the redis and the database store give.
- No queue worker and no SSR process are needed.

### PHP-FPM

`pm = dynamic`, `pm.max_children` around 20 on a 2 GB server, and `request_terminate_timeout = 60`. PHP 8.4 needs `pdo_sqlite`, and the `redis` extension when Redis is used.

### nginx

In the site's server block:

```nginx
client_max_body_size 64k;

location ~ \.php$ {
    # Forge's existing fastcgi lines stay.
    fastcgi_read_timeout 60s;
}
```

- The copilot streams its replies as `text/event-stream`. Keep that type out of `gzip_types`, and never add `fastcgi_ignore_headers X-Accel-Buffering`: the stream sends `X-Accel-Buffering: no` so nginx passes each part on at once.
- Keep `server_name` to `demo.agentic-actions.com`. The app also answers 400 to any host other than `APP_URL`'s.

An optional per-address limit at the edge also covers requests that reach PHP before the app's own limits, such as MCP calls without a token. Put this in the `http` context (on Forge, a file in `/etc/nginx/conf.d/`); it leaves Anthropic's range and the health check alone:

```nginx
geo $from_claude {
    default 0;
    160.79.104.0/21 1;
}

map "$from_claude:$uri" $demo_limit_key {
    "~^1:" "";
    "0:/up" "";
    default $binary_remote_addr;
}

limit_req_zone $demo_limit_key zone=demo_per_ip:10m rate=10r/s;
```

and this in the server block:

```nginx
limit_req zone=demo_per_ip burst=40 nodelay;
limit_req_status 429;
```

### After each deploy

```bash
curl -si -X POST https://demo.agentic-actions.com/mcp/t/x -H 'Accept: application/json, text/event-stream' | grep -i -E '^HTTP|resource_metadata'   # 401, with resource_metadata
curl -so /dev/null -w '%{http_code}\n' https://demo.agentic-actions.com/_inertia/devtools/entries     # 404
curl -so /dev/null -w '%{http_code}\n' https://demo.agentic-actions.com/register                      # 404
php artisan tinker --execute 'echo App\Ai\ScriptedPlanner::active() ? "scripted" : "LIVE";'             # scripted
```

Then click "Try the demo" and run one copilot chip. Do not print the config on the host (`config:show`), since it includes the app key.

## License

MIT. See [LICENSE](LICENSE).
