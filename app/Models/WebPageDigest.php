<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached deep_researcher page summary — keyed by (url, content, params), so a
 * re-run over an unchanged page reuses the digest instead of re-summarizing.
 */
class WebPageDigest extends Model
{
    protected $fillable = [
        'url_hash', 'content_hash', 'params_hash', 'model', 'digest', 'hit_count',
    ];
}
