<?php

namespace Database\Seeders;

use App\Actions\Demo\SampleBoard;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed two teams, four people with the starter kit's roles, and a realistic board per team. Every password is
     * "password". The data itself is SampleBoard's, which the hosted demo's sandboxes use too.
     *
     *   owner@example.com   Olivia Park   owner of Acme
     *   member@example.com  Marcus Reed   member of Acme and of Globex
     *   viewer@example.com  Vera Lind     viewer of Acme (reads the board, changes nothing)
     *   globex@example.com  Gus Novak     owner of Globex only
     */
    public function run(SampleBoard $sample): void
    {
        $person = fn (string $name, string $email): User => $sample->person($name, $email, 'password', str("{$name}'s Team")->slug()->toString());

        $sample->teams([
            'owner' => $person('Olivia Park', 'owner@example.com'),
            'member' => $person('Marcus Reed', 'member@example.com'),
            'viewer' => $person('Vera Lind', 'viewer@example.com'),
            'globexOwner' => $person('Gus Novak', 'globex@example.com'),
        ], acmeSlug: 'acme', globexSlug: 'globex');
    }
}
