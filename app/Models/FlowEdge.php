<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowEdge extends Model
{
    protected $fillable = [
        'flow_id', 'flow_version_id', 'from_node_key', 'to_node_key', 'from_port', 'to_port', 'label',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'flow_version_id');
    }
}
