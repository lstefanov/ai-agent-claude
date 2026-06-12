<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Services\KnowledgeService;
use Illuminate\Http\JsonResponse;

/**
 * Per-flow toggle за базата знания (огледало на FlowMemoryController::toggle):
 * изключва "ЗНАНИЕ" блока и knowledge_search за конкретния flow, без да пипа
 * самата база на фирмата.
 */
class FlowKnowledgeController extends Controller
{
    public function toggle(Flow $flow): JsonResponse
    {
        $settings = (array) ($flow->settings ?? []);
        $current = (bool) ($settings['knowledge']['enabled'] ?? true);
        $settings['knowledge'] = array_merge((array) ($settings['knowledge'] ?? []), ['enabled' => ! $current]);
        $flow->update(['settings' => $settings]);

        return response()->json(['enabled' => KnowledgeService::enabledForFlow($flow)]);
    }
}
