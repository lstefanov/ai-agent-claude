<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only одит на всеки MCP tool call. Само sanitize-нати params (без
 * tokens) и кратко result_summary (без съдържанието на write операции).
 */
class ConnectorToolLog extends Model
{
    // Само created_at (DB го попълва чрез useCurrent) — без updated_at.
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'connector_id', 'flow_run_id', 'node_run_id',
        'tool', 'params', 'status', 'result_summary', 'error', 'duration_ms', 'created_at',
    ];

    protected $casts = [
        'params' => 'array',
        'created_at' => 'datetime',
    ];

    public function connector(): BelongsTo
    {
        return $this->belongsTo(CompanyConnector::class, 'connector_id');
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(FlowRun::class);
    }

    public function nodeRun(): BelongsTo
    {
        return $this->belongsTo(NodeRun::class);
    }
}
