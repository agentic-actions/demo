<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $project_id
 * @property int|null $assignee_id
 * @property int|null $created_by
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Project|null $project
 * @property-read User|null $assignee
 * @property-read User|null $creator
 */
#[Fillable(['team_id', 'project_id', 'assignee_id', 'created_by', 'title', 'description', 'status', 'priority', 'due_on', 'completed_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * Get the team the task belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the project the task is filed under, if any.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the team member the task is assigned to, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Get the user who created the task.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Only tasks that are past their due date and not done.
     *
     * @param  Builder<Task>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where('status', '!=', TaskStatus::Done->value)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', today());
    }

    /**
     * Whether the task is past its due date and not done.
     */
    public function isOverdue(): bool
    {
        return $this->status !== TaskStatus::Done
            && $this->due_on !== null
            && $this->due_on->lt(today());
    }

    /**
     * Move the task to a status, keeping completed_at in step with it.
     */
    public function moveTo(TaskStatus $status): void
    {
        $this->status = $status;
        $this->completed_at = $status === TaskStatus::Done ? ($this->completed_at ?? now()) : null;
    }

    /**
     * The task as the board, the JSON routes and agents read it.
     *
     * @return array{id: int, title: string, description: string|null, status: string, priority: string, due_on: string|null, overdue: bool, assignee: array{name: string, email: string}|null, project: string|null}
     */
    public function toBoardArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'due_on' => $this->due_on?->toDateString(),
            'overdue' => $this->isOverdue(),
            'assignee' => $this->assignee === null ? null : [
                'name' => $this->assignee->name,
                'email' => $this->assignee->email,
            ],
            'project' => $this->project?->name,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }
}
