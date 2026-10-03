<?php

namespace App\Actions\Demo;

use AgenticActions\OAuth\McpConnection;
use AgenticActions\Streaming\AgenticConversation;
use AgenticActions\Streaming\AgenticView;
use App\Models\DemoSandbox;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Passport\Passport;
use Laravel\Sanctum\Sanctum;

/**
 * Everything a hosted visitor's sandbox left behind, deleted in one transaction: its people, its teams (soft-deleted
 * ones too) and every row keyed by either.
 *
 * Most of those rows have no foreign key, so they are deleted by id here, before the teams and the people:
 * - MCP connections, revoked first, then every Passport access token, refresh token, auth code and device code of the
 *   sandbox's people, and any client one of them owns;
 * - Sanctum tokens;
 * - laravel/ai's conversations and messages (the package's key to each goes by foreign key), and the tables the
 *   package kept for reloads;
 * - database sessions (a Redis session ends with its TTL, and finds no one to sign in) and password reset rows.
 *
 * Deleting the teams cascades to their members, invitations, projects and tasks, and deleting the people to their
 * passkeys. Cache entries (rate limits, approvals, the change feed) are left to expire: Redis drops them itself, and
 * with the database store a daily task in routes/console.php deletes the expired rows. Since ids are never reused, one
 * left for a deleted id never reaches anyone else.
 */
final class DeleteSandbox
{
    /**
     * Delete the sandbox and everything in it.
     */
    public function handle(DemoSandbox $sandbox): void
    {
        DB::transaction(function () use ($sandbox): void {
            $people = User::query()->where('demo_sandbox_id', $sandbox->id)->get(['id', 'email']);
            $userIds = $people->pluck('id')->push($sandbox->visitor_id)->filter()->unique()->values();
            $teamIds = Team::withTrashed()->where('demo_sandbox_id', $sandbox->id)->pluck('id');

            $this->deleteOAuth($userIds, $teamIds);
            $this->deleteConversations($userIds, $teamIds);

            Sanctum::personalAccessTokenModel()::query()
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $userIds)
                ->delete();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table((string) config('session.table'))->whereIn('user_id', $userIds)->delete();
            }

            DB::table((string) config('auth.passwords.users.table'))->whereIn('email', $people->pluck('email'))->delete();

            Team::withTrashed()->whereIn('id', $teamIds)->forceDelete();
            User::query()->whereIn('id', $userIds)->delete();

            $sandbox->delete();
        });
    }

    /**
     * Revoke the sandbox's MCP connections, then delete its people's Passport tokens, codes and clients.
     *
     * @param  Collection<int, int>  $userIds
     * @param  Collection<int, int>  $teamIds
     */
    private function deleteOAuth(Collection $userIds, Collection $teamIds): void
    {
        McpConnection::query()
            ->where(fn (Builder $query) => $this->ownedBy($query, 'user', $userIds))
            ->orWhere(fn (Builder $query) => $this->ownedBy($query, 'tenant', $teamIds, new Team))
            ->get()
            ->each(fn (McpConnection $connection) => $connection->revoke());

        $tokens = Passport::token()->newQuery()->whereIn('user_id', $userIds);

        Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->delete();
        $tokens->delete();
        Passport::authCode()->newQuery()->whereIn('user_id', $userIds)->delete();
        Passport::deviceCode()->newQuery()->whereIn('user_id', $userIds)->delete();
        Passport::client()->newQuery()->where(fn (Builder $query) => $this->ownedBy($query, 'owner', $userIds))->delete();
    }

    /**
     * Delete the copilot's conversations with the sandbox's people or in its teams, their messages and the tables the
     * package kept. The package's key to each conversation goes with it, by foreign key.
     *
     * @param  Collection<int, int>  $userIds
     * @param  Collection<int, int>  $teamIds
     */
    private function deleteConversations(Collection $userIds, Collection $teamIds): void
    {
        $conversationIds = Conversation::query()
            ->where(fn (Builder $query) => $this->ownedBy($query, 'participant', $userIds))
            ->pluck('id')
            ->merge(AgenticConversation::query()
                ->where(fn (Builder $query) => $this->ownedBy($query, 'participant', $userIds))
                ->orWhere(fn (Builder $query) => $this->ownedBy($query, 'tenant', $teamIds, new Team))
                ->pluck('conversation_id'))
            ->unique()
            ->values();

        AgenticView::query()
            ->where(fn (Builder $query) => $this->ownedBy($query, 'participant', $userIds))
            ->orWhere(fn (Builder $query) => $this->ownedBy($query, 'tenant', $teamIds, new Team))
            ->orWhereIn('conversation_id', $conversationIds)
            ->delete();

        ConversationMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->orWhere(fn (Builder $query) => $this->ownedBy($query, 'participant', $userIds))
            ->delete();

        Conversation::query()->whereIn('id', $conversationIds)->delete();
    }

    /**
     * Narrow a query to rows whose morph pair names one of these models: people by default, or teams.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  Collection<int, int>  $ids
     * @return Builder<TModel>
     */
    private function ownedBy(Builder $query, string $morph, Collection $ids, User|Team $model = new User): Builder
    {
        return $query->where("{$morph}_type", $model->getMorphClass())->whereIn("{$morph}_id", $ids);
    }
}
