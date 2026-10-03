<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;

/**
 * A short-lived public HTTPS address for this Herd site, so a remote MCP client such as a Claude custom connector can
 * reach it (DEMO.md, "Connect Claude.ai (custom connector)"). It refuses while any account still has the seeded
 * password, opens a Cloudflare quick tunnel, sets APP_PUBLIC_URL to it while it runs, and prints each team's connector
 * URL. On Ctrl-C, or when the time is up, it stops the tunnel and clears APP_PUBLIC_URL.
 */
#[Signature('app:tunnel {--minutes=30 : Stop the tunnel after this many minutes}')]
#[Description('Open a short-lived public tunnel for a Claude custom connector or another remote MCP client')]
class Tunnel extends Command
{
    /**
     * Whether Ctrl-C or a TERM signal asked the tunnel to stop.
     */
    private bool $stopping = false;

    /**
     * Open the tunnel, keep it open for the minutes given, then close it.
     */
    public function handle(): int
    {
        $weak = User::query()->get()->filter(fn (User $user): bool => Hash::check('password', $user->password))->pluck('email');

        if ($weak->isNotEmpty()) {
            $this->components->error('Anyone could sign in as '.$weak->implode(', ').' with the seeded password. Change the demo passwords first (DEMO.md, "Connect Claude.ai").');

            return self::FAILURE;
        }

        if ($this->laravel->configurationIsCached()) {
            $this->components->error('The config is cached, so APP_PUBLIC_URL would not reach it. Run php artisan config:clear first.');

            return self::FAILURE;
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $tunnel = new Process(['cloudflared', 'tunnel', '--no-autoupdate', '--url', "https://{$host}", '--http-host-header', $host, '--no-tls-verify']);
        $tunnel->setTimeout(null)->start();
        $this->trap([SIGINT, SIGTERM], function (): void {
            $this->stopping = true;
        });

        $url = null;
        for ($second = 0; $second < 60 && $url === null && $tunnel->isRunning() && ! $this->stopping; $second++) {
            sleep(1);
            $url = preg_match('#https://[a-z0-9-]+\.trycloudflare\.com#', $tunnel->getErrorOutput(), $match) === 1 ? $match[0] : null;
        }

        if ($url === null) {
            $tunnel->stop();
            $this->components->error('cloudflared gave no public URL: '.trim($tunnel->getErrorOutput()));

            return self::FAILURE;
        }

        $this->setPublicUrl($url);
        $minutes = max(1, (int) $this->option('minutes'));
        $this->components->info("The tunnel is open for {$minutes} minutes: {$url}. Ctrl-C closes it sooner.");
        $this->components->twoColumnDetail('<fg=gray>Team</>', '<fg=gray>Connector URL (paste it into Claude)</>');

        Team::query()->where('is_personal', false)->orderBy('name')->each(fn (Team $team) => $this->components->twoColumnDetail(
            $team->name,
            $url.parse_url(route('agentic-actions.mcp.tenant', ['current_team' => $team->slug]), PHP_URL_PATH),
        ));

        $deadline = now()->addMinutes($minutes);
        while (! $this->stopping && $tunnel->isRunning() && now()->lessThan($deadline)) {
            sleep(1);
        }

        $tunnel->stop();
        $this->setPublicUrl('');
        $this->components->info('The tunnel is closed and APP_PUBLIC_URL is empty again.');

        return self::SUCCESS;
    }

    /**
     * Write APP_PUBLIC_URL into .env, adding the line when it is missing. The rest of the file is kept as it is.
     */
    private function setPublicUrl(string $url): void
    {
        $path = $this->laravel->environmentFilePath();
        $contents = (string) file_get_contents($path);
        $line = "APP_PUBLIC_URL={$url}";

        file_put_contents($path, preg_match('/^APP_PUBLIC_URL=.*$/m', $contents) === 1
            ? (string) preg_replace_callback('/^APP_PUBLIC_URL=.*$/m', fn (): string => $line, $contents)
            : rtrim($contents, "\n")."\n{$line}\n");
    }
}
