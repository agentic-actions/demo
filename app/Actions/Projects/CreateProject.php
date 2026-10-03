<?php

namespace App\Actions\Projects;

use AgenticActions\Action;
use AgenticActions\ActionContext;
use AgenticActions\Attributes\Expose;
use AgenticActions\Effect;
use AgenticActions\Refusal;
use App\Actions\Concerns\InteractsWithBoard;
use App\Enums\TeamPermission;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\ValidatedInput;

/**
 * The board's "New project" dialog posts to this action's generated route with a plain Inertia <Form>.
 */
#[Expose]
final class CreateProject extends Action
{
    use InteractsWithBoard;

    protected string $description = 'Create a project in the current team. Tasks can then be filed under it by name.';

    protected ?Effect $effect = Effect::Write;

    protected array $touches = ['projects'];

    /**
     * The project's fields.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->min(1)->max(80)->required()->description('A short name, unique within the team.'),
            'description' => $schema->string()->max(500)->nullable()->description('What the project is for. Optional.'),
        ];
    }

    /**
     * The new project.
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
        ];
    }

    /**
     * Owners and admins only.
     */
    public function authorize(ActionContext $context): bool
    {
        return $this->allows($context, TeamPermission::CreateProject);
    }

    /**
     * Save the project, refusing a name the team already uses. On the hosted demo, a team holds at most
     * demo.caps.projects_per_team projects.
     *
     * @throws Refusal on "name" when the team already has a project by that name, or when the hosted demo team is full
     */
    public function handle(ActionContext $context, ValidatedInput $input): Project
    {
        $team = $this->team($context);
        $name = $input->string('name')->trim()->toString();

        $this->ensureRoomFor($team->projects()->count(), 'projects_per_team', 'This demo team holds :max projects, as many as it can.');

        if ($team->projects()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw Refusal::make('This team already has a project by that name.')->on('name');
        }

        return $team->projects()->create([
            'name' => $name,
            'description' => $input->input('description'),
        ]);
    }

    /**
     * The copilot row: "Creating a project…" while it runs, "Created a project" once it succeeded.
     */
    public function activityLabel(ActionContext $context, bool $finished): string
    {
        return $finished ? __('Created a project') : __('Creating a project…');
    }
}
