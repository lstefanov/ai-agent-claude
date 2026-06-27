<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Типови персони по роля×вертикал — храна за PersonaService при предлагане на персони.
 * Manager-архетипите носят и демография + готов портрет (casting-кандидатите).
 */
class PersonaArchetype extends Model
{
    protected $fillable = [
        'vertical', 'role', 'name', 'age', 'gender', 'ethnicity', 'background',
        'traits', 'tone', 'bio_template', 'avatar_path', 'embedding',
    ];

    protected $casts = [
        'traits' => 'array',
        'embedding' => 'array',
    ];

    /**
     * Публичен URL на готовия портрет — само ако файлът реално съществува на public диска;
     * иначе null → casting картата пада на буква-fallback (преди да е генериран портретът).
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->avatar_path) ? $disk->url($this->avatar_path) : null;
    }
}
