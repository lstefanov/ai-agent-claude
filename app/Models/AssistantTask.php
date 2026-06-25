<?php

namespace App\Models;

use App\Support\ModelLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantTask extends Model
{
    protected $fillable = [
        'org_member_id', 'current_director_member_id', 'flow_id',
        'title', 'description', 'trigger', 'schedule', 'act_mode',
        'approval_policy', 'star_tier', 'tier_stale', 'kpi', 'status', 'gen_token',
        'run_after_generate',
    ];

    protected $casts = [
        'run_after_generate' => 'boolean',
        'tier_stale' => 'boolean',
    ];

    /** Асистент-членът, на когото виси задачата (стабилната идентичност). */
    public function orgMember(): BelongsTo
    {
        return $this->belongsTo(OrgMember::class);
    }

    /** Текущото подчинение — кой директор-член я надзирава сега (по избор). */
    public function currentDirectorMember(): BelongsTo
    {
        return $this->belongsTo(OrgMember::class, 'current_director_member_id');
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    /** Задачата извършва реално действие (write конектор), не само чернова. */
    public function isWriteAct(): bool
    {
        return in_array($this->act_mode, ['act', 'mixed'], true);
    }

    /** star_tier е null → наследява нивото от члена-собственик. */
    public function inheritsTier(): bool
    {
        return $this->star_tier === null;
    }

    /**
     * Каноничното правило за „с кое ниво се пуска задачата": star_tier ?? нивото на
     * члена, после cap по абонамента (Plan::max_star_tier). Суровият star_tier НЕ се
     * ползва директно при оценка/изпълнение — само това.
     */
    public function effectiveStarTier(): ModelLevel
    {
        $base = $this->star_tier
            ? ModelLevel::from($this->star_tier)
            : $this->orgMember->defaultStarTier();

        $ceiling = $this->orgMember->company->subscription?->plan?->maxStarTier();

        return $ceiling ? $base->cappedAt($ceiling) : $base;
    }
}
