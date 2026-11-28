<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeTimesheet;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeTimesheetFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<EmployeeTimesheet>
     */
    protected $model = EmployeeTimesheet::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date' => $this->faker->date(),
            'time_start' => $this->faker->dateTime(),
            'time_end' => $this->faker->dateTime(),
            'category' => $this->faker->word(),
            'number_of_hours' => $this->faker->randomFloat(2, 0.5, 8),
            'session_id' => null,
            'approval_status' => EmployeeTimesheet::APPROVAL_PENDING,
        ];
    }
}
