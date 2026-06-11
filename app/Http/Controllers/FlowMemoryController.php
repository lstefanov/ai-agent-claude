<?php

namespace App\Http\Controllers;

use App\Models\Flow;
use App\Services\FlowMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AJAX endpoints за "Памет" панела в builder-а: преглед на запомненото
 * съдържание/поуките, toggle per flow и изчистване.
 */
class FlowMemoryController extends Controller
{
    public function show(Flow $flow): JsonResponse
    {
        $outputs = $flow->memories()
            ->where('kind', 'output')
            ->latest('id')
            ->take(500)
            ->get(['id', 'node_key', 'title', 'summary', 'embedding_provider', 'meta', 'created_at'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'node_key' => $m->node_key,
                'node_name' => $m->meta['node_name'] ?? $m->node_key,
                'title' => $m->title,
                'summary' => $m->summary,
                'has_embedding' => $m->embedding_provider !== null,
                'created_at' => $m->created_at->format('d.m.Y H:i'),
            ]);

        $lessons = $flow->memories()
            ->where('kind', 'lesson')
            ->latest('id')
            ->take(500)
            ->get(['id', 'node_key', 'summary', 'created_at'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'node_key' => $m->node_key,
                'summary' => $m->summary,
                'created_at' => $m->created_at->format('d.m.Y H:i'),
            ]);

        return response()->json([
            'enabled' => FlowMemoryService::enabled($flow),
            'outputs' => $outputs,
            'lessons' => $lessons,
        ]);
    }

    public function toggle(Flow $flow): JsonResponse
    {
        $settings = (array) ($flow->settings ?? []);
        $current = (bool) ($settings['memory']['enabled'] ?? true);
        $settings['memory'] = array_merge((array) ($settings['memory'] ?? []), ['enabled' => ! $current]);
        $flow->update(['settings' => $settings]);

        return response()->json(['enabled' => FlowMemoryService::enabled($flow)]);
    }

    public function clear(Request $request, Flow $flow, FlowMemoryService $memory): JsonResponse
    {
        $kind = $request->query('kind');
        $deleted = $memory->clear($flow, is_string($kind) ? $kind : null);

        return response()->json(['deleted' => $deleted]);
    }
}
