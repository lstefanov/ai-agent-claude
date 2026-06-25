<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global scrape cache: one row per normalized URL, holding the latest
 * rendered markdown + its content hash. TTL/hash semantics live in
 * WebPageCacheService.
 */
class WebPageCache extends Model
{
    protected $table = 'web_page_cache';

    protected $fillable = [
        'url_hash', 'url', 'content_hash', 'markdown', 'title',
        'meta_description', 'links', 'fetched_at', 'last_checked_at',
        'hit_count', 'meta',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'links' => 'array',
        'meta' => 'array',
    ];
}
