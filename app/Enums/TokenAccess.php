<?php

namespace App\Enums;

use App\Models\Team;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * What an MCP client may do with a token from the team's "AI clients" page. Over MCP the package counts an ability only
 * when the token names it, so a token lists each one: actions:read, actions:write for Read and write, and
 * tenant:{team id}, which binds it to that one team. Never Sanctum's default "*", which lists no tools over MCP.
 */
enum TokenAccess: string
{
    case Read = 'read';
    case ReadWrite = 'write';

    /**
     * Get the display label for the access.
     */
    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read only',
            self::ReadWrite => 'Read and write',
        };
    }

    /**
     * The abilities a token with this access holds, bound to the given team.
     *
     * @return list<string>
     */
    public function abilities(Team $team): array
    {
        return [
            self::ability('read'),
            ...($this === self::ReadWrite ? [self::ability('write')] : []),
            self::teamAbility($team),
        ];
    }

    /**
     * The ability that binds a token to the given team, by its primary key: "tenant:1".
     */
    public static function teamAbility(Team $team): string
    {
        return self::ability('tenant').$team->getKey();
    }

    /**
     * The access the token's abilities give it.
     */
    public static function of(PersonalAccessToken $token): self
    {
        return self::fromAbilities($token->abilities ?? []);
    }

    /**
     * The access a list of abilities gives: a Sanctum token's, or the scopes a person approved for an OAuth client.
     *
     * @param  array<int, string>  $abilities
     */
    public static function fromAbilities(array $abilities): self
    {
        return in_array(self::ability('write'), $abilities, true) ? self::ReadWrite : self::Read;
    }

    /**
     * The package's name for an ability, from config/agentic-actions.php.
     */
    private static function ability(string $key): string
    {
        return (string) config("agentic-actions.abilities.{$key}");
    }
}
