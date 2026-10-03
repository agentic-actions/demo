<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property int|null $demo_sandbox_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, Task> $tasks
 */
#[Fillable(['name', 'slug', 'is_personal', 'demo_sandbox_id'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = $team->demo_sandbox_id === null
                    ? static::generateUniqueTeamSlug($team->name)
                    : static::generateSandboxTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = $team->demo_sandbox_id === null
                    ? static::generateUniqueTeamSlug($team->name, $team->id)
                    : static::generateSandboxTeamSlug($team->name);
            }
        });
    }

    /**
     * A slug for a hosted sandbox's team: the name with a random suffix, as CreateSandbox gives Acme and Globex. The
     * kit's numbered slugs ("launch", then "launch-1") would tell one visitor which team names another chose.
     */
    protected static function generateSandboxTeamSlug(string $name): string
    {
        do {
            $slug = ltrim(Str::slug($name).'-'.Str::lower(Str::random(6)), '-');
        } while (static::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the team's projects.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get the team's tasks.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Find a member by email or by name, ignoring case. Email wins when both could match.
     */
    public function findMember(string $emailOrName): ?User
    {
        $needle = mb_strtolower(trim($emailOrName));

        if ($needle === '') {
            return null;
        }

        return $this->members()->whereRaw('LOWER(users.email) = ?', [$needle])->first()
            ?? $this->members()->whereRaw('LOWER(users.name) = ?', [$needle])->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
