<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Tests\Support\Boards;

/*
 * Every task action through its generated route, POST /{current_team}/actions/{action}, as a JSON caller signed in
 * with a session: the owner, admin, member and viewer of Acme, and Globex's owner, who is not in Acme at all.
 */

beforeEach(function () {
    $this->boards = Boards::make();
});

describe('create-task', function () {
    it('lets owners, admins and members create a task, naming people and projects in words', function (string $role) {
        $boards = $this->boards;

        $this->actingAs($boards->{$role})
            ->postJson(Boards::url('create-task', $boards->acme), [
                'title' => 'Order new office chairs',
                'assignee' => 'Marcus Reed',
                'project' => 'website RELAUNCH',
                'priority' => 'low',
                'due_on' => '2026-10-15',
            ])
            ->assertOk()
            ->assertExactJsonStructure(['task' => ['id', 'title', 'description', 'status', 'priority', 'due_on', 'overdue', 'assignee' => ['name', 'email'], 'project']])
            ->assertJsonPath('task.title', 'Order new office chairs')
            ->assertJsonPath('task.assignee.email', 'member@example.com')
            ->assertJsonPath('task.project', 'Website relaunch')
            ->assertJsonPath('task.status', 'todo')
            ->assertJsonPath('task.due_on', '2026-10-15');

        $task = Task::query()->where('title', 'Order new office chairs')->sole();

        expect($task->team_id)->toBe($boards->acme->id)
            ->and($task->created_by)->toBe($boards->{$role}->id)
            ->and($task->assignee_id)->toBe($boards->member->id)
            ->and($task->priority)->toBe(TaskPriority::Low);
    })->with(['owner', 'admin', 'member']);

    it('assigns by email as well as by name', function () {
        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('create-task', $this->boards->acme), ['title' => 'Check the invoices', 'assignee' => 'VIEWER@example.com'])
            ->assertOk()
            ->assertJsonPath('task.assignee.name', 'Vera Lind');
    });

    it('refuses a viewer', function () {
        $this->actingAs($this->boards->viewer)
            ->postJson(Boards::url('create-task', $this->boards->acme), ['title' => 'Sneaky task'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to do this.');

        $this->assertDatabaseMissing('tasks', ['title' => 'Sneaky task']);
    });

    it('keeps a user out of a team they do not belong to', function () {
        $this->actingAs($this->boards->globexOwner)
            ->postJson(Boards::url('create-task', $this->boards->acme), ['title' => 'Planted task'])
            ->assertForbidden();

        $this->assertDatabaseMissing('tasks', ['title' => 'Planted task']);
    });

    it('puts an unknown assignee on the assignee field', function () {
        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('create-task', $this->boards->acme), ['title' => 'Hire a designer', 'assignee' => 'Gus Novak'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignee' => 'No one in this team goes by that name or email.']);
    });

    it('puts an unknown project on the project field', function () {
        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('create-task', $this->boards->acme), ['title' => 'Hire a designer', 'project' => 'Warehouse move'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project' => 'This team has no project by that name.']);
    });

    it('validates the input against schema()', function () {
        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('create-task', $this->boards->acme), ['priority' => 'urgent', 'due_on' => 'tomorrow'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'priority', 'due_on']);
    });

    it('validates without running when asked by Precognition', function () {
        $this->actingAs($this->boards->owner)
            ->withHeaders(['Precognition' => 'true', 'Precognition-Validate-Only' => 'title'])
            ->postJson(Boards::url('create-task', $this->boards->acme), ['title' => 'Only checking'])
            ->assertNoContent()
            ->assertHeader('Precognition-Success', 'true');

        $this->assertDatabaseMissing('tasks', ['title' => 'Only checking']);
    });

    it('answers a browser form with a redirect back and the result in the session', function () {
        $this->actingAs($this->boards->owner)
            ->from('/acme/board')
            ->post(Boards::url('create-task', $this->boards->acme), ['title' => 'From a plain form'])
            ->assertRedirect('/acme/board')
            ->assertSessionHas('action.name', 'create-task')
            ->assertSessionHas('action.output.task.title', 'From a plain form');
    });
});

describe('update-task', function () {
    it('moves a task to done and stamps completed_at', function () {
        $this->actingAs($this->boards->member)
            ->postJson(Boards::url('update-task', $this->boards->acme), ['task' => $this->boards->acmeTask->id, 'status' => 'done'])
            ->assertOk()
            ->assertJsonPath('task.status', 'done');

        $task = $this->boards->acmeTask->fresh();

        expect($task->status)->toBe(TaskStatus::Done)
            ->and($task->completed_at)->not->toBeNull();
    });

    it('changes only the fields it is sent', function () {
        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('update-task', $this->boards->acme), ['task' => $this->boards->acmeTask->id, 'priority' => 'low', 'assignee' => null])
            ->assertOk();

        $task = $this->boards->acmeTask->fresh();

        expect($task->priority)->toBe(TaskPriority::Low)
            ->and($task->assignee_id)->toBeNull()
            ->and($task->title)->toBe('Write the launch announcement')
            ->and($task->project_id)->toBe($this->boards->website->id);
    });

    it('refuses a viewer', function () {
        $this->actingAs($this->boards->viewer)
            ->postJson(Boards::url('update-task', $this->boards->acme), ['task' => $this->boards->acmeTask->id, 'status' => 'done'])
            ->assertForbidden();

        expect($this->boards->acmeTask->fresh()->status)->toBe(TaskStatus::Todo);
    });

    it('reads another team\'s task number as not found, even for an owner', function () {
        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('update-task', $this->boards->acme), ['task' => $this->boards->globexTask->id, 'status' => 'done'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');

        expect($this->boards->globexTask->fresh()->status)->toBe(TaskStatus::Todo);
    });

    it('keeps a member of both teams inside the team in the URL', function () {
        $this->actingAs($this->boards->member)
            ->postJson(Boards::url('update-task', $this->boards->globex), ['task' => $this->boards->acmeTask->id, 'status' => 'done'])
            ->assertNotFound();

        $this->actingAs($this->boards->member)
            ->postJson(Boards::url('update-task', $this->boards->globex), ['task' => $this->boards->globexTask->id, 'status' => 'doing'])
            ->assertOk();

        expect($this->boards->acmeTask->fresh()->status)->toBe(TaskStatus::Todo)
            ->and($this->boards->globexTask->fresh()->status)->toBe(TaskStatus::Doing);
    });

    it('keeps a Globex-only user away from Acme\'s tasks', function () {
        $this->actingAs($this->boards->globexOwner)
            ->postJson(Boards::url('update-task', $this->boards->acme), ['task' => $this->boards->acmeTask->id, 'status' => 'done'])
            ->assertForbidden();

        $this->actingAs($this->boards->globexOwner)
            ->postJson(Boards::url('update-task', $this->boards->globex), ['task' => $this->boards->acmeTask->id, 'status' => 'done'])
            ->assertNotFound();

        expect($this->boards->acmeTask->fresh()->status)->toBe(TaskStatus::Todo);
    });
});

describe('delete-task', function () {
    it('lets owners and admins delete', function (string $role) {
        $this->actingAs($this->boards->{$role})
            ->postJson(Boards::url('delete-task', $this->boards->acme), ['task' => $this->boards->acmeTask->id])
            ->assertOk()
            ->assertExactJson([]);

        $this->assertModelMissing($this->boards->acmeTask);
    })->with(['owner', 'admin']);

    it('refuses members and viewers', function (string $role) {
        $this->actingAs($this->boards->{$role})
            ->postJson(Boards::url('delete-task', $this->boards->acme), ['task' => $this->boards->acmeTask->id])
            ->assertForbidden();

        $this->assertModelExists($this->boards->acmeTask);
    })->with(['member', 'viewer']);

    it('reads another team\'s task number as not found', function () {
        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('delete-task', $this->boards->acme), ['task' => $this->boards->globexTask->id])
            ->assertNotFound();

        $this->assertModelExists($this->boards->globexTask);
    });
});

describe('list-tasks', function () {
    it('lets every member read the board, viewers included, and only their team\'s tasks', function (string $role) {
        $this->actingAs($this->boards->{$role})
            ->postJson(Boards::url('list-tasks', $this->boards->acme))
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.id', $this->boards->acmeTask->id)
            ->assertJsonPath('tasks.0.assignee.name', 'Olivia Park');
    })->with(['owner', 'member', 'viewer']);

    it('filters by status, assignee, project and overdue', function () {
        $boards = $this->boards;

        Task::factory()->doing()->create(['team_id' => $boards->acme->id, 'title' => 'In progress', 'assignee_id' => $boards->member->id, 'due_on' => null]);
        Task::factory()->overdue()->create(['team_id' => $boards->acme->id, 'title' => 'Late one']);

        $titles = fn (array $filters): array => $this->actingAs($boards->member)
            ->postJson(Boards::url('list-tasks', $boards->acme), $filters)
            ->assertOk()
            ->collect('tasks')
            ->pluck('title')
            ->all();

        expect($titles(['status' => 'doing']))->toBe(['In progress'])
            ->and($titles(['assignee' => 'me']))->toBe(['In progress'])
            ->and($titles(['assignee' => 'owner@example.com']))->toBe(['Write the launch announcement'])
            ->and($titles(['project' => 'Website relaunch']))->toBe(['Write the launch announcement'])
            ->and($titles(['overdue' => true]))->toBe(['Late one']);
    });

    it('refuses a filter that names nobody in the team', function () {
        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('list-tasks', $this->boards->acme), ['assignee' => 'globex@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignee');
    });

    it('keeps a Globex-only user out', function () {
        $this->actingAs($this->boards->globexOwner)
            ->postJson(Boards::url('list-tasks', $this->boards->acme))
            ->assertForbidden();
    });
});

describe('search-tasks', function () {
    it('finds tasks by words in the title or details, in this team only', function () {
        $this->actingAs($this->boards->viewer)
            ->postJson(Boards::url('search-tasks', $this->boards->acme), ['query' => 'newsletter'])
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Write the launch announcement');

        $this->actingAs($this->boards->viewer)
            ->postJson(Boards::url('search-tasks', $this->boards->acme), ['query' => 'lease'])
            ->assertOk()
            ->assertJsonCount(0, 'tasks');
    });

    it('treats LIKE wildcards as plain text', function () {
        Task::factory()->create(['team_id' => $this->boards->acme->id, 'title' => 'Reach 100% uptime']);

        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('search-tasks', $this->boards->acme), ['query' => '100%'])
            ->assertOk()
            ->assertJsonCount(1, 'tasks');

        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('search-tasks', $this->boards->acme), ['query' => '_%'])
            ->assertOk()
            ->assertJsonCount(0, 'tasks');
    });

    it('needs at least two characters', function () {
        $this->actingAs($this->boards->owner)
            ->postJson(Boards::url('search-tasks', $this->boards->acme), ['query' => 'a'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');
    });
});

describe('summarize-board', function () {
    it('counts the team\'s tasks by status, overdue, due soon and unassigned', function () {
        $acme = $this->boards->acme;

        Task::factory()->doing()->create(['team_id' => $acme->id, 'due_on' => null, 'assignee_id' => $this->boards->member->id]);
        Task::factory()->done()->create(['team_id' => $acme->id, 'due_on' => today()->subDay()]);
        Task::factory()->overdue()->create(['team_id' => $acme->id, 'assignee_id' => null]);

        $this->actingAs($this->boards->viewer)
            ->postJson(Boards::url('summarize-board', $acme))
            ->assertOk()
            ->assertExactJson([
                'total' => 4,
                'todo' => 2,
                'doing' => 1,
                'done' => 1,
                'overdue' => 1,
                'due_soon' => 1,
                'unassigned' => 1,
            ]);
    });
});

describe('complete-task', function () {
    it('has no generated route, because its agentSchema() speaks titles, not numbers', function () {
        $this->actingAs($this->boards->owner)
            ->postJson('/acme/actions/complete-task', ['task' => $this->boards->acmeTask->id])
            ->assertNotFound();

        expect($this->boards->acmeTask->fresh()->status)->toBe(TaskStatus::Todo);
    });
});
