<?php

namespace App\Http\Controllers;

use App\Jobs\IngestResourceJob;
use App\Jobs\IngestUrlResourceJob;
use App\Models\Company;
use App\Models\KnowledgeConflict;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeFact;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeGap;
use App\Models\KnowledgePage;
use App\Models\KnowledgeResource;
use App\Services\Knowledge\KnowledgeConflictService;
use App\Services\Knowledge\KnowledgeIntakeService;
use App\Services\KnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "База знания" v2 (NotebookLM стил): РЕСУРСИ (url / файл / снимка / бележка)
 * + страници per url ресурс + факти + одит-история + пропуски. Папките са
 * визуална организация. Ingest-ът минава през опашката (IngestResourceJob /
 * IngestUrlResourceJob) и UI-ът поллва по status.
 */
class CompanyKnowledgeController extends Controller
{
    public function index(Company $company): View
    {
        return view('companies.knowledge', [
            'company' => $company,
            'config' => [
                'base' => route('companies.knowledge.index', $company),
                'companyName' => $company->name,
                'backUrl' => route('companies.show', $company),
                'csrf' => csrf_token(),
            ],
        ]);
    }

    public function data(Company $company, KnowledgeService $knowledge): JsonResponse
    {
        $folders = $company->knowledgeFolders()
            ->withCount('resources')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name'])
            ->map(fn ($f) => [
                'id' => $f->id,
                'parent_id' => $f->parent_id,
                'name' => $f->name,
                'doc_count' => $f->resources_count,
            ]);

        $factCategories = $company->knowledgeFacts()
            ->active()
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->toArray();

        return response()->json([
            'enabled' => KnowledgeService::enabled($company),
            'folders' => $folders,
            'fact_categories' => $factCategories,
            'stats' => [
                'resources' => $company->knowledgeResources()->count(),
                'ready' => $company->knowledgeResources()->where('status', 'ready')->count(),
                'pages' => (int) $company->knowledgePages()->count(),
                'chunks' => (int) $company->knowledgeChunks()->count(),
                'facts' => $company->knowledgeFacts()->active()->count(),
                'events' => $company->knowledgeEvents()->count(),
                'gaps' => KnowledgeGap::where('company_id', $company->id)->count(),
                'conflicts' => KnowledgeConflict::where('company_id', $company->id)->open()->count(),
                'cost_usd' => round((float) $company->knowledgeResources()->sum('cost_usd'), 4),
                'foreign_provider_chunks' => $knowledge->foreignProviderChunks($company),
                'provider_tag' => $knowledge->providerTag(),
            ],
            'busy' => $company->knowledgeResources()->whereIn('status', ['pending', 'processing'])->exists(),
        ]);
    }

    public function listResources(Request $request, Company $company): JsonResponse
    {
        $perPage = 15;
        $search = (string) $request->query('search', '');
        $folderId = $request->query('folder_id');
        $sort = (string) $request->query('sort', 'created_at');
        $dir = (string) $request->query('dir', 'desc');
        $page = max(1, (int) $request->query('page', 1));

        $sort = in_array($sort, ['title', 'status', 'created_at', 'chunk_count']) ? $sort : 'created_at';
        $dir = $dir === 'asc' ? 'asc' : 'desc';

        $query = $company->knowledgeResources()->withCount('pages');

        if ($search !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')
                ->orWhere('url', 'like', '%'.$search.'%'));
        }
        if ($folderId !== null) {
            $query->where('folder_id', (int) $folderId);
        }

        $total = $query->count();
        $items = $query->orderBy($sort, $dir)
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(fn (KnowledgeResource $r) => [
                'id' => $r->id,
                'folder_id' => $r->folder_id,
                'type' => $r->type,
                'title' => $r->title,
                'original_name' => $r->original_name,
                'mime' => $r->mime,
                'size_bytes' => $r->size_bytes,
                'url' => $r->url,
                'content' => $r->type === 'note' ? $r->content : null,
                'status' => $r->status,
                'error' => $r->error,
                'chunk_count' => $r->chunk_count,
                'pages_count' => $r->pages_count,
                'cost_usd' => (float) $r->cost_usd,
                'has_digest' => ! empty($r->meta['digest']),
                'progress' => $r->meta['progress'] ?? null,
                'partial' => (bool) ($r->meta['partial'] ?? false),
                'ingested_at' => $r->ingested_at?->format('d.m.Y H:i'),
                'created_at' => $r->created_at->format('d.m.Y H:i'),
            ]);

        return response()->json([
            'items' => $items,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function listFacts(Request $request, Company $company): JsonResponse
    {
        $perPage = 15;
        $category = (string) $request->query('category', 'all');
        $page = max(1, (int) $request->query('page', 1));

        $query = $company->knowledgeFacts()->active()->latest('updated_at');

        if ($category !== 'all' && $category !== '') {
            $query->where('category', $category);
        }

        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(fn (KnowledgeFact $f) => [
                'id' => $f->id,
                'category' => $f->category,
                'location' => $f->location,
                'name' => $f->name,
                'value' => mb_substr($f->value, 0, 600),
                'source_type' => $f->source_type,
                'flow_run_id' => $f->flow_run_id,
                'confidence' => $f->confidence,
                'updated_at' => $f->updated_at->format('d.m.Y H:i'),
            ]);

        return response()->json([
            'items' => $items,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function listEvents(Request $request, Company $company): JsonResponse
    {
        $perPage = 15;
        $page = max(1, (int) $request->query('page', 1));

        $query = $company->knowledgeEvents()->latest('id');
        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'action' => $e->action,
                'subject_type' => $e->subject_type,
                'subject_id' => $e->subject_id,
                'title' => $e->title,
                'snippet' => $e->snippet ? mb_substr($e->snippet, 0, 2000) : null,
                'source' => $e->source,
                'created_at' => $e->created_at->format('d.m.Y H:i'),
            ]);

        return response()->json([
            'items' => $items,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function listGaps(Request $request, Company $company): JsonResponse
    {
        $perPage = 15;
        $page = max(1, (int) $request->query('page', 1));

        $query = KnowledgeGap::where('company_id', $company->id)->latest('id');
        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(fn (KnowledgeGap $g) => [
                'id' => $g->id,
                'query' => $g->query,
                'best_score' => $g->best_score,
                'status' => $g->status,
                'resolved_by' => $g->resolved_by,
                'flow_run_id' => $g->flow_run_id,
                'node_key' => $g->node_key,
                'created_at' => $g->created_at->format('d.m.Y H:i'),
            ]);

        return response()->json([
            'items' => $items,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Конфликти (противоречиви факти от различни източници)
    // ──────────────────────────────────────────────────────────────────────

    public function listConflicts(Request $request, Company $company): JsonResponse
    {
        $perPage = 15;
        $page = max(1, (int) $request->query('page', 1));

        $query = KnowledgeConflict::where('company_id', $company->id)->open()->latest('id');
        $total = $query->count();
        $conflicts = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $factIds = $conflicts->flatMap(fn (KnowledgeConflict $c) => (array) $c->fact_ids)->unique()->values();
        $facts = $factIds->isNotEmpty()
            ? KnowledgeFact::whereIn('id', $factIds)->get()->keyBy('id')
            : collect();
        $sources = $this->resolveFactSources($facts);

        $items = $conflicts->map(fn (KnowledgeConflict $c) => [
            'id' => $c->id,
            'subject' => $c->subject,
            'category' => $c->category,
            'location' => $c->location,
            'created_at' => $c->created_at->format('d.m.Y H:i'),
            'candidates' => collect((array) $c->fact_ids)
                ->map(fn ($id) => $facts->get($id))
                ->filter()
                ->map(fn (KnowledgeFact $f) => [
                    'fact_id' => $f->id,
                    'value' => $f->value,
                    'source' => $sources[$f->id] ?? ucfirst((string) $f->source_type),
                    'created_at' => $f->created_at?->format('d.m.Y H:i'),
                    'confidence' => $f->confidence,
                    'active' => $f->status === 'active',
                ])
                ->values(),
        ]);

        return response()->json([
            'items' => $items,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function scanConflicts(Company $company, KnowledgeConflictService $service): JsonResponse
    {
        return response()->json(['found' => $service->scan($company)]);
    }

    public function resolveConflict(Request $request, Company $company, KnowledgeConflict $conflict, KnowledgeConflictService $service): JsonResponse
    {
        abort_unless($conflict->company_id === $company->id, 404);
        $data = $request->validate(['winner_fact_id' => 'required|integer']);

        if ($conflict->status !== 'open') {
            return response()->json(['error' => 'Конфликтът вече е обработен.'], 422);
        }
        if (! in_array((int) $data['winner_fact_id'], array_map('intval', (array) $conflict->fact_ids), true)) {
            return response()->json(['error' => 'Невалиден избор.'], 422);
        }

        $service->resolve($conflict, (int) $data['winner_fact_id']);

        return response()->json(['ok' => true]);
    }

    public function ignoreConflict(Company $company, KnowledgeConflict $conflict, KnowledgeConflictService $service): JsonResponse
    {
        abort_unless($conflict->company_id === $company->id, 404);
        $service->ignore($conflict);

        return response()->json(['ok' => true]);
    }

    /**
     * Човешки етикет на източника per факт (resource/page/run/chat) — bulk,
     * за да няма N+1.
     *
     * @param  Collection<int, KnowledgeFact>  $facts
     * @return array<int, string>
     */
    private function resolveFactSources(Collection $facts): array
    {
        $resourceIds = $facts->where('source_type', 'resource')->pluck('source_id')->filter()->unique();
        $pageIds = $facts->where('source_type', 'page')->pluck('source_id')->filter()->unique();

        $resources = $resourceIds->isNotEmpty()
            ? KnowledgeResource::whereIn('id', $resourceIds)->get(['id', 'type', 'title', 'original_name', 'url'])->keyBy('id')
            : collect();
        $pages = $pageIds->isNotEmpty()
            ? KnowledgePage::whereIn('id', $pageIds)->get(['id', 'url', 'title'])->keyBy('id')
            : collect();

        $labels = [];
        foreach ($facts as $f) {
            $labels[$f->id] = match ($f->source_type) {
                'resource' => (function () use ($f, $resources) {
                    $r = $resources->get($f->source_id);
                    if (! $r) {
                        return 'Документ #'.$f->source_id;
                    }

                    return $r->type === 'url'
                        ? 'URL: '.($r->title ?: $r->url)
                        : 'Документ: '.($r->original_name ?: $r->title);
                })(),
                'page' => (function () use ($f, $pages) {
                    $p = $pages->get($f->source_id);

                    return $p ? 'Страница: '.($p->url ?: $p->title) : 'Страница #'.$f->source_id;
                })(),
                'run' => 'Run #'.($f->flow_run_id ?: $f->source_id),
                'chat' => 'Чат',
                default => ucfirst((string) $f->source_type),
            };
        }

        return $labels;
    }

    public function toggle(Company $company): JsonResponse
    {
        $settings = (array) ($company->settings ?? []);
        $current = (bool) ($settings['knowledge']['enabled'] ?? true);
        $settings['knowledge'] = array_merge((array) ($settings['knowledge'] ?? []), ['enabled' => ! $current]);
        $company->update(['settings' => $settings]);

        return response()->json(['enabled' => KnowledgeService::enabled($company)]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Папки (визуална организация)
    // ──────────────────────────────────────────────────────────────────────

    public function storeFolder(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'parent_id' => 'nullable|integer|exists:knowledge_folders,id',
        ]);

        if (! empty($data['parent_id'])) {
            $parent = KnowledgeFolder::find($data['parent_id']);
            abort_unless($parent && $parent->company_id === $company->id, 404);
        }

        $folder = $company->knowledgeFolders()->create([
            'name' => trim($data['name']),
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        return response()->json(['id' => $folder->id]);
    }

    public function renameFolder(Request $request, Company $company, KnowledgeFolder $folder): JsonResponse
    {
        abort_unless($folder->company_id === $company->id, 404);

        $data = $request->validate(['name' => 'required|string|max:150']);
        $folder->update(['name' => trim($data['name'])]);

        return response()->json(['ok' => true]);
    }

    public function destroyFolder(Company $company, KnowledgeFolder $folder): JsonResponse
    {
        abort_unless($folder->company_id === $company->id, 404);

        // Подпапките се трият каскадно; ресурсите падат в корена (FK nullOnDelete).
        $folder->delete();

        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Ресурси
    // ──────────────────────────────────────────────────────────────────────

    /** Качване на файлове/снимки — тип image при image/* mime, иначе upload. */
    public function upload(Request $request, Company $company, KnowledgeIntakeService $intake): JsonResponse
    {
        $maxKb = (int) config('services.knowledge.max_file_mb', 20) * 1024;

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => "required|file|max:{$maxKb}|extensions:pdf,txt,md,docx,xlsx,xls,csv,jpg,jpeg,png",
            'folder_id' => 'nullable|integer|exists:knowledge_folders,id',
        ]);

        $folderId = $this->validatedFolderId($request, $company);

        $created = [];
        $skipped = [];

        foreach ($request->file('files', []) as $file) {
            $resource = $intake->fromUpload($company, $file, ['folder_id' => $folderId]);

            if ($resource === null) {
                $skipped[] = $file->getClientOriginalName();

                continue;
            }

            $created[] = $resource->id;
        }

        return response()->json(['created' => $created, 'skipped' => $skipped]);
    }

    /** Бележка, създадена през FlowAI (заглавие + текст). */
    public function storeNote(Request $request, Company $company, KnowledgeIntakeService $intake): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:300',
            'content' => 'required|string|min:3|max:100000',
            'folder_id' => 'nullable|integer|exists:knowledge_folders,id',
        ]);

        $resource = $intake->fromText($company, $data['title'], $data['content'], [
            'folder_id' => $this->validatedFolderId($request, $company),
        ]);

        return response()->json(['id' => $resource->id], 201);
    }

    /** Редакция на бележка → re-ingest (обновява и "забравя" старото). */
    public function updateNote(Request $request, Company $company, KnowledgeResource $resource): JsonResponse
    {
        abort_unless($resource->company_id === $company->id && $resource->type === 'note', 404);

        $data = $request->validate([
            'title' => 'required|string|max:300',
            'content' => 'required|string|min:3|max:100000',
        ]);

        if ($resource->status === 'processing') {
            return response()->json(['error' => 'Бележката се обработва в момента.'], 409);
        }

        $resource->update([
            'title' => trim($data['title']),
            'content' => $data['content'],
            'status' => 'pending',
            'error' => null,
        ]);

        IngestResourceJob::dispatch($resource->id);

        return response()->json(['ok' => true]);
    }

    /** URL ресурс — сайт или конкретна страница; обхожда се BFS на опашката. */
    public function storeUrl(Request $request, Company $company, KnowledgeIntakeService $intake): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|string|max:2048|url:http,https',
            'folder_id' => 'nullable|integer|exists:knowledge_folders,id',
        ]);

        try {
            $resource = $intake->fromUrl($company, $data['url'], [
                'folder_id' => $this->validatedFolderId($request, $company),
            ]);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Невалиден URL адрес.'], 422);
        }

        if ($resource === null) {
            return response()->json(['error' => 'Този URL вече е добавен като ресурс.'], 422);
        }

        return response()->json(['id' => $resource->id], 201);
    }

    public function destroyResource(Company $company, KnowledgeResource $resource, KnowledgeService $knowledge): JsonResponse
    {
        abort_unless($resource->company_id === $company->id, 404);

        $knowledge->deleteResource($resource);

        return response()->json(['ok' => true]);
    }

    public function reingest(Request $request, Company $company, KnowledgeResource $resource): JsonResponse
    {
        abort_unless($resource->company_id === $company->id, 404);

        if ($resource->status === 'processing') {
            return response()->json(['error' => 'Ресурсът се обработва в момента.'], 409);
        }

        $resource->update(['status' => 'pending', 'error' => null]);

        $resource->type === 'url'
            ? IngestUrlResourceJob::dispatch($resource->id, force: $request->boolean('force'))
            : IngestResourceJob::dispatch($resource->id);

        return response()->json(['ok' => true]);
    }

    /** Сваляне на оригиналния файл (upload/image). */
    public function download(Company $company, KnowledgeResource $resource): StreamedResponse
    {
        abort_unless(
            $resource->company_id === $company->id
            && $resource->storage_path
            && Storage::disk('local')->exists($resource->storage_path),
            404,
        );

        return Storage::disk('local')->download(
            $resource->storage_path,
            $resource->original_name ?: ($resource->title.'.bin'),
        );
    }

    /** Извлечената информация (digest) на ресурс — за модала "Преглед". */
    public function digest(Company $company, KnowledgeResource $resource): JsonResponse
    {
        abort_unless($resource->company_id === $company->id, 404);

        return response()->json([
            'title' => $resource->title,
            'digest' => (string) ($resource->meta['digest'] ?? ''),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Страници на url ресурс
    // ──────────────────────────────────────────────────────────────────────

    public function pages(Company $company, KnowledgeResource $resource): JsonResponse
    {
        abort_unless($resource->company_id === $company->id && $resource->type === 'url', 404);

        $pages = $resource->pages()
            ->orderBy('url')
            ->get()
            ->map(fn (KnowledgePage $p) => [
                'id' => $p->id,
                'url' => $p->url,
                'title' => $p->title,
                'meta_description' => $p->meta_description,
                'status' => $p->status,
                'parsed_at' => $p->parsed_at?->format('d.m.Y H:i'),
            ]);

        return response()->json(['pages' => $pages]);
    }

    public function pageDigest(Company $company, KnowledgePage $page): JsonResponse
    {
        abort_unless($page->company_id === $company->id, 404);

        return response()->json([
            'title' => $page->title ?: $page->url,
            'url' => $page->url,
            'meta_description' => $page->meta_description,
            'digest' => (string) $page->digest,
            'parsed_at' => $page->parsed_at?->format('d.m.Y H:i'),
        ]);
    }

    public function destroyPage(Company $company, KnowledgePage $page, KnowledgeService $knowledge): JsonResponse
    {
        abort_unless($page->company_id === $company->id, 404);

        $knowledge->deletePage($page);

        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Факти + пропуски
    // ──────────────────────────────────────────────────────────────────────

    public function destroyFact(Company $company, KnowledgeFact $fact): JsonResponse
    {
        abort_unless($fact->company_id === $company->id, 404);

        KnowledgeEvent::log(
            $company->id, 'deleted', 'fact', $fact->id, $fact->name,
            $fact->value, 'изтрит ръчно от потребителя',
            ['category' => $fact->category, 'location' => $fact->location],
        );

        $fact->delete();

        return response()->json(['ok' => true]);
    }

    public function clearGaps(Company $company): JsonResponse
    {
        $deleted = KnowledgeGap::where('company_id', $company->id)->delete();

        return response()->json(['deleted' => $deleted]);
    }

    private function validatedFolderId(Request $request, Company $company): ?int
    {
        $folderId = $request->integer('folder_id') ?: null;
        if ($folderId !== null) {
            $folder = KnowledgeFolder::find($folderId);
            abort_unless($folder && $folder->company_id === $company->id, 404);
        }

        return $folderId;
    }
}
