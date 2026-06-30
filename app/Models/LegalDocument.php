<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Admin-editable, versioned legal text (e.g. the registration policy). */
class LegalDocument extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'title', 'body', 'version', 'requires_acceptance'];

    protected $casts = [
        'version' => 'integer',
        'requires_acceptance' => 'boolean',
    ];

    /** Documents a user must accept during onboarding. */
    public static function requiredForAcceptance()
    {
        return static::where('requires_acceptance', true)->orderBy('title')->get();
    }
}
