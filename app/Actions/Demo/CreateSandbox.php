<?php

namespace App\Actions\Demo;

use App\Models\DemoSandbox;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A hosted visitor's own copy of the demo, in one transaction: the visitor ("You", Acme's owner), Marcus Reed (Acme
 * and Globex member), Vera Lind (Acme viewer) and Gus Novak (Globex owner), each verified with a personal team, and
 * Acme and Globex with their sample boards, due dates counted from today.
 *
 * Nobody knows any of the passwords: the visitor gets back in with the signed resume link, and the others are reached
 * only through "View as". Emails end in the reserved .invalid domain, so nothing could ever be mailed to them. Every
 * slug carries a random suffix, so one sandbox's team URLs cannot be guessed from another's. The password hash, the slow
part, is made before the transaction opens: on SQLite the transaction holds the database's write lock.
 */
final class CreateSandbox
{
    public function __construct(private SampleBoard $sample) {}

    /**
     * Create the sandbox and return it with its visitor loaded.
     */
    public function handle(?string $ip = null): DemoSandbox
    {
        try {
            return $this->create($ip);
        } catch (UniqueConstraintViolationException) {
            // A random email or slug collided with another sandbox's: one more try with new ones.
            return $this->create($ip);
        }
    }

    /**
     * Build the whole sandbox, or nothing.
     */
    private function create(?string $ip): DemoSandbox
    {
        $password = Hash::make(Str::random(40));

        return DB::transaction(function () use ($ip, $password): DemoSandbox {
            $sandbox = DemoSandbox::create([
                'ip_hash' => $ip === null ? null : DemoSandbox::hashIp($ip),
                'expires_at' => now()->addHours((int) config('demo.ttl_hours')),
            ]);

            $person = fn (string $name, string $handle, ?string $personalTeamName = null): User => $this->sample->person(
                $name,
                "{$handle}-{$this->suffix()}@sandbox.invalid",
                $password,
                "{$handle}-{$this->suffix()}",
                $sandbox->id,
                $personalTeamName,
            );

            $people = [
                'owner' => $person('You', 'you', 'Personal'),
                'member' => $person('Marcus Reed', 'marcus'),
                'viewer' => $person('Vera Lind', 'vera'),
                'globexOwner' => $person('Gus Novak', 'gus'),
            ];

            $this->sample->teams($people, "acme-{$this->suffix()}", "globex-{$this->suffix()}", $sandbox->id);

            $sandbox->update(['visitor_id' => $people['owner']->id]);

            return $sandbox->setRelation('visitor', $people['owner']);
        });
    }

    /**
     * Six random lowercase letters and digits.
     */
    private function suffix(): string
    {
        return Str::lower(Str::random(6));
    }
}
