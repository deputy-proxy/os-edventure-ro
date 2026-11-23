<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Process;
use Hexters\HexaLite\Models\HexaRole;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcessFactory extends Factory
{
    /** @var class-string<Process> */
    protected $model = Process::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Process $process): void {
            // Keep the original required column aligned with the canonical owner_id.
            $process->owner = $process->owner_id;
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraphs(3, true),
            'steps' => [],
            'owner_id' => Employee::factory(),
            'main_topic' => $this->faker->word(),
            'visible_to' => fn (): array => ($roleId = HexaRole::query()->where('guard', (string) config('mcp.domain_permission_guard', config('auth.defaults.guard', 'web')))->value('id')) ? [(int) $roleId] : [],
        ];
    }
}
