<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\OffboardingRequest;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $activeEmployees = Employee::factory(50)->create();

        $offboardingEmployees = Employee::factory(20)->offboarding()->create();

        $offboardingEmployees->each(function (Employee $employee) {
            OffboardingRequest::factory()->for($employee)->create();
        });

        // Ensure there is a healthy spread of activity in the current month
        // so month-to-date widgets (completion rate, monthly trend) aren't empty.
        Employee::factory(6)->offboarding()->create()->each(function (Employee $employee) {
            $createdAt = now()->subDays(fake()->numberBetween(0, 20));
            $lastWorkingDay = (clone $createdAt)->addDays(fake()->numberBetween(15, 45));
            $status = fake()->randomElement(['pending', 'in_progress', 'completed']);

            OffboardingRequest::factory()->for($employee)->create([
                'created_at' => $createdAt,
                'notice_date' => $createdAt,
                'last_working_day' => $lastWorkingDay,
                'status' => $status,
                'completed_at' => $status === 'completed' ? now()->subDays(fake()->numberBetween(0, 5)) : null,
            ]);
        });
    }
}
