<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'title' => rtrim(fake()->sentence(4), '.'),
            'description' => fake()->boolean() ? fake()->paragraph() : null,
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Normal,
            'due_on' => fake()->boolean() ? fake()->dateTimeBetween('-1 week', '+3 weeks') : null,
        ];
    }

    /**
     * Indicate that the task is in progress.
     */
    public function doing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Doing,
        ]);
    }

    /**
     * Indicate that the task is done.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the task is past its due date and not done.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Todo,
            'due_on' => today()->subDays(3),
        ]);
    }
}
