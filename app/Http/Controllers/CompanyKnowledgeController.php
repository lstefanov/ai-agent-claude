<?php

namespace App\Http\Controllers;

use App\Jobs\IngestKnowledgeDocumentJob;
use App\Models\Company;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeFolder;
use App\Services\KnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "База знания" на фирмата: страница + AJAX endpoints (Alpine polling) по
 * конвенциите на FlowMemoryController. Папките са организационни; документите
 * минават през опашката (IngestKnowledgeDocumentJob) и UI-ът ги поллва по
 * status, докато има pending/processing.
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
                'editUrl' => route('companies.edit', $company),
                'websiteUrl' => $company->website_url,
                'csrf' => csrf_token(),
            ],
        ]);
    }

    public function data(Company $company, KnowledgeService $knowledge): JsonResponse
    {
        $folders = $company->knowledgeFolders()
            ->withCount('documents')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name'])
            ->map(fn ($f) => [
                'id' => $f->id,
                'parent_id' => $f->parent_id,
                'name' => $f->name,
                'doc_count' => $f->documents_count,
            ]);

        $documents = $company->knowledgeDocuments()
            ->latest('id')
            ->take(500)
            ->get()
            ->map(fn (KnowledgeDocument $d) => [
                'id' => $d->id,
                'folder_id' => $d->folder_id,
                'source_type' => $d->source_type,
                'title' => $d->title,
                'original_name' => $d->original_name,
                'mime' => $d->mime,
                'size_bytes' => $d->size_bytes,
                'source_url' => $d->source_url,
                'status' => $d->status,
                'error' => $d->error,
                'chunk_count' => $d->chunk_count,
                'cost_usd' => (float) $d->cost_usd,
                'ingested_at' => $d->ingested_at?->format('d.m.Y H:i'),
                'created_at' => $d->created_at->format('d.m.Y H:i'),
            ]);

        $busy = $company->knowledgeDocuments()->whereIn('status', ['pending', 'processing'])->exists();

        return response()->json([
            'enabled' => KnowledgeService::enabled($company),
            'folders' => $folders,
            'documents' => $documents,
            'stats' => [
                'documents' => $documents->count(),
                'ready' => $documents->where('status', 'ready')->count(),
                'chunks' => (int) $company->knowledgeChunks()->count(),
                'cost_usd' => round((float) $company->knowledgeDocuments()->sum('cost_usd'), 4),
                'foreign_provider_chunks' => $knowledge->foreignProviderChunks($company),
                'provider_tag' => $knowledge->providerTag(),
            ],
            'busy' => $busy,
        ]);
    }

    public function toggle(Company $company): JsonResponse
    {
        $settings = (array) ($company->settings ?? []);
        $current = (bool) ($settings['knowledge']['enabled'] ?? true);
        $settings['knowledge'] = array_merge((array) ($settings['knowledge'] ?? []), ['enabled' => ! $current]);
        $company->update(['settings' => $settings]);

        return response()->json(['enabled' => KnowledgeService::enabled($company)]);
    }

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

        // Подпапките се трият каскадно; документите падат в корена (FK nullOnDelete).
        $folder->delete();

        return response()->json(['ok' => true]);
    }

    public function upload(Request $request, Company $company): JsonResponse
    {
        $maxKb = (int) config('services.knowledge.max_file_mb', 20) * 1024;

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => "required|file|max:{$maxKb}|extensions:pdf,txt,md,docx,xlsx,csv,jpg,jpeg,png",
            'folder_id' => 'nullable|integer|exists:knowledge_folders,id',
        ]);

        $folderId = $request->integer('folder_id') ?: null;
        if ($folderId !== null) {
            $folder = KnowledgeFolder::find($folderId);
            abort_unless($folder && $folder->company_id === $company->id, 404);
        }

        $created = [];
        $skipped = [];

        foreach ($request->file('files', []) as $file) {
            $hash = hash_file('sha256', $file->getRealPath());

            $duplicate = $company->knowledgeDocuments()
                ->where('status', 'ready')
                ->where('meta->file_sha256', $hash)
                ->exists();

            if ($duplicate) {
                $skipped[] = $file->getClientOriginalName();

                continue;
            }

            $path = $file->store("knowledge/{$company->id}", 'local');

            $document = $company->knowledgeDocuments()->create([
                'folder_id' => $folderId,
                'source_type' => 'upload',
                'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'storage_path' => $path,
                'status' => 'pending',
                'meta' => ['file_sha256' => $hash],
            ]);

            IngestKnowledgeDocumentJob::dispatch($document->id);
            $created[] = $document->id;
        }

        return response()->json(['created' => $created, 'skipped' => $skipped]);
    }

    public function destroyDocument(Company $company, KnowledgeDocument $document, KnowledgeService $knowledge): JsonResponse
    {
        abort_unless($document->company_id === $company->id, 404);

        $knowledge->deleteDocument($document);

        return response()->json(['ok' => true]);
    }

    public function reingest(Company $company, KnowledgeDocument $document): JsonResponse
    {
        abort_unless($document->company_id === $company->id, 404);

        if ($document->status === 'processing') {
            return response()->json(['error' => 'Документът се обработва в момента.'], 409);
        }

        $document->update(['status' => 'pending', 'error' => null]);
        IngestKnowledgeDocumentJob::dispatch($document->id);

        return response()->json(['ok' => true]);
    }

    public function searchTest(Request $request, Company $company, KnowledgeService $knowledge): JsonResponse
    {
        $data = $request->validate(['query' => 'required|string|max:500']);

        $hits = $knowledge->search($company, $data['query'], logGaps: false, llmContext: [
            'company_id' => $company->id,
        ]);

        return response()->json(['hits' => $hits]);
    }
}
