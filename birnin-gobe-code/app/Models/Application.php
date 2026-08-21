<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'submitted_snapshot' => 'array',
        ];
    }

    public function isCompleteForActiveCampaign(): bool
    {
        // Placeholder: replace with Campaign-configured validation engine.
        return true;
    }

    public function buildSubmissionSnapshot(): array
    {
        // Snapshot must include resolved form/rules/answers/version references at submission time.
        return ['application_id' => $this->getKey(), 'version' => 1];
    }
}
