<?php

namespace Database\Factories;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
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

    /**
     * Attache les réponses d'une section, comme le ferait le candidat.
     *
     * L'avancement est recalculé par `ApplicationProgress` — la règle du
     * domaine — et non fixé à la main : une fixture qui poserait son propre
     * pourcentage testerait sa propre invention.
     *
     * @param  array<string, mixed>  $answers
     */
    public function withSection(ApplicationSection $section, array $answers, bool $complete = true): static
    {
        return $this->afterCreating(function (Application $application) use ($section, $answers, $complete): void {
            ApplicationSectionAnswers::query()->create([
                'application_id' => $application->getKey(),
                'section' => $section->value,
                'answers' => $answers,
                'completed_at' => $complete ? now() : null,
            ]);

            $application->forceFill([
                'current_step' => $section->value,
                'completion_percent' => ApplicationProgress::forApplication($application),
            ])->save();
        });
    }
}
