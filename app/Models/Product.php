<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['organization_id', 'template_id', 'name', 'category'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function passports(): HasMany
    {
        return $this->hasMany(Passport::class);
    }
}
