<?php

namespace App\Console\Commands;

use App\Actions\Demo\DeleteSandbox;
use App\Models\DemoSandbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Delete every hosted-demo sandbox whose time is up, each in its own transaction (see DeleteSandbox for what goes).
 * Scheduled every fifteen minutes in routes/console.php; a live sandbox is never touched.
 *
 * A sandbox goes five minutes after it expires, not at once. Its people are signed out at expiry (EndExpiredSandbox),
 * but a copilot turn started just before may still be writing for up to a minute (the assistant's reply lock), and
 * its messages, keyed by a conversation with no foreign key, would outlive a sandbox deleted under it.
 */
#[Signature('demo:prune-sandboxes')]
#[Description('Delete expired demo sandboxes: their people, teams, tokens, connections and conversations')]
class PruneSandboxes extends Command
{
    /**
     * How long after its expiry a sandbox is deleted.
     */
    public const GRACE_MINUTES = 5;

    /**
     * Delete the expired sandboxes, oldest first.
     */
    public function handle(DeleteSandbox $delete): int
    {
        $count = 0;

        DemoSandbox::query()->where('expires_at', '<=', now()->subMinutes(self::GRACE_MINUTES))->lazyById()->each(function (DemoSandbox $sandbox) use ($delete, &$count): void {
            $delete->handle($sandbox);
            $count++;
        });

        $this->components->info("Deleted {$count} expired ".str('sandbox')->plural($count).'.');

        return self::SUCCESS;
    }
}
