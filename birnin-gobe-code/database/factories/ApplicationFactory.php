<?php

namespace Database\Factories;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
final class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'candidate_id' => User::factory(),
            'status' => ApplicationStatus::DRAFT,
            'current_step' => ApplicationSection::firstImplemented(),
            'completion_percent' => 0,
        ];
    }

    public function status(ApplicationStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
