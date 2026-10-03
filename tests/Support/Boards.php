<?php

namespace Tests\Support;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

/**
 * Two teams and one user per role of the starter kit:
 *
 *   Acme    owner (Owner), admin (Admin), member (Member), viewer (Viewer)
 *   Globex  globexOwner (Owner), member (Member)
 *
 * with a project and a task on each board. globexOwner belongs to Globex only.
 */
final class Boards
{
    public function __construct(
        public Team $acme,
        public Team $globex,
        public User $owner,
        public User $admin,
        public User $member,
        public User $viewer,
        public User $globexOwner,
        public Project $website,
        public Task $acmeTask,
        public Task $globexTask,
    ) {
        //
    }

    /**
     * Create the two boards.
     */
    public static function make(): self
    {
        $owner = User::factory()->create(['name' => 'Olivia Park', 'email' => 'owner@example.com']);
        $admin = User::factory()->create(['name' => 'Ada Admin', 'email' => 'admin@example.com']);
        $member = User::factory()->create(['name' => 'Marcus Reed', 'email' => 'member@example.com']);
        $viewer = User::factory()->create(['name' => 'Vera Lind', 'email' => 'viewer@example.com']);
        $globexOwner = User::factory()->create(['name' => 'Gus Novak', 'email' => 'globex@example.com']);

        $acme = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
        $globex = Team::factory()->create(['name' => 'Globex', 'slug' => 'globex']);

        foreach ([[$owner, TeamRole::Owner], [$admin, TeamRole::Admin], [$member, TeamRole::Member], [$viewer, TeamRole::Viewer]] as [$user, $role]) {
            $acme->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        }

        foreach ([[$globexOwner, TeamRole::Owner], [$member, TeamRole::Member]] as [$user, $role]) {
            $globex->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        }

        $website = Project::factory()->create(['team_id' => $acme->id, 'name' => 'Website relaunch']);

        $acmeTask = Task::factory()->create([
            'team_id' => $acme->id,
            'project_id' => $website->id,
            'assignee_id' => $owner->id,
            'title' => 'Write the launch announcement',
            'description' => 'One page for the blog and a short version for the newsletter.',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::High,
            'due_on' => today()->addDays(2),
        ]);

        $globexTask = Task::factory()->create([
            'team_id' => $globex->id,
            'title' => 'Cancel the Elm Street lease',
            'description' => 'Notice period is 30 days.',
            'status' => TaskStatus::Todo,
            'due_on' => null,
        ]);

        return new self($acme, $globex, $owner, $admin, $member, $viewer, $globexOwner, $website, $acmeTask, $globexTask);
    }

    /**
     * The generated route of an action inside a team.
     */
    public static function url(string $action, Team $team): string
    {
        return route("actions.{$action}", ['current_team' => $team->slug]);
    }

    /**
     * The team's MCP URL: /mcp/t/{slug}.
     */
    public static function mcpUrl(Team $team): string
    {
        return route('agentic-actions.mcp.tenant', ['current_team' => $team->slug]);
    }

    /**
     * One JSON-RPC request to the MCP server.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function rpc(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, ...($params === [] ? [] : ['params' => $params])];
    }

    /**
     * The headers an MCP client sends with a bearer token. Passed per request, so no later request carries it.
     *
     * @return array<string, string>
     */
    public static function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'];
    }
}
