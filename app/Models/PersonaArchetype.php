<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Типови персони по роля×вертикал — храна за PersonaService при предлагане на персони.
 */
class PersonaArchetype extends Model
{
    protected $fillable = [
        'vertical', 'role', 'name', 'traits', 'tone', 'bio_template', 'embedding',
    ];

    protected $casts = [
        'traits' => 'array',
        'embedding' => 'array',
    ];
}
