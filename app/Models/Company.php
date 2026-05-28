<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = ['name', 'description', 'industry', 'language'];

    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }
}
