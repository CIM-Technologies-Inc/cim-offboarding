<?php

namespace Database\Factories;

use App\Models\SeparationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OffboardingRequest>
 */
class OffboardingRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $createdAt = fake()->dateTimeBetween('-11 months', 'now');
        $lastWorkingDay = (clone $createdAt)->modify('+'.fake()->numberBetween(15, 45).' days');
        $status = fake()->randomElement(['pending', 'pending', 'in_progress', 'in_progress', 'completed', 'completed', 'completed', 'cancelled']);

        $completedAt = $status === 'completed'
            ? fake()->dateTimeBetween($createdAt, (clone $lastWorkingDay)->modify('+5 days'))
            : null;

        // Snapshotted the same way `OffboardingRequestController::store()`
        // does for a real request — title/description/notice period frozen
        // from whichever Separation Type happens to be picked, never a live
        // reference. Falls back to a plain literal if Separation Type
        // Management has no rows at all (e.g. a factory run before its seed
        // migration), so this factory never hard-depends on seed order.
        $separationType = SeparationType::inRandomOrder()->first();
        $noticePeriodDays = $separationType->default_notice_period_days ?? 30;

        return [
            'reason' => $separationType->title ?? 'Resignation',
            'separation_type_id' => $separationType?->id,
            'separation_type_description' => $separationType->description ?? "Employee's voluntary termination of employment.",
            'notice_period_days' => $noticePeriodDays,
            'notification_date' => (clone $createdAt)->modify("+{$noticePeriodDays} days"),
            'notice_date' => $createdAt,
            'last_working_day' => $lastWorkingDay,
            'status' => $status,
            'remarks' => fake()->optional(0.4)->sentence(),
            'completed_at' => $completedAt,
            'created_at' => $createdAt,
            'updated_at' => $completedAt ?? $createdAt,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending', 'completed_at' => null]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => 'in_progress', 'completed_at' => null]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => fake()->dateTimeBetween($attributes['created_at'] ?? '-1 month', 'now'),
        ]);
    }
}
