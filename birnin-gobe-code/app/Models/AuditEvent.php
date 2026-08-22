<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_value' => 'array', 'new_value' => 'array'];
    }
}
