<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    private static array $departments = ['Engineering', 'Sales', 'Marketing', 'Finance', 'Human Resources', 'Operations'];

    private static array $designations = ['Associate', 'Senior Associate', 'Team Lead', 'Manager', 'Senior Manager'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'employee_code' => 'EMP-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'department' => fake()->randomElement(self::$departments),
            'designation' => fake()->randomElement(self::$designations),
            'date_of_joining' => fake()->dateTimeBetween('-6 years', '-3 months'),
            'status' => 'active',
        ];
    }

    public function offboarding(): static
    {
        return $this->state(fn () => ['status' => 'offboarding']);
    }

    public function offboarded(): static
    {
        return $this->state(fn () => ['status' => 'offboarded']);
    }
}
