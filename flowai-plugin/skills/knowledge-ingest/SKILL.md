---
name: knowledge-ingest
description: >
  Use this skill when extending the FlowAI knowledge base, for requests like "add a
  knowledge source", "ingest a new document type", "add an ingest step", or "change how
  resources are chunked or embedded". Covers the KnowledgeIngestor pipeline and embeddings.
---

# Extend FlowAI knowledge ingest

Add a source type or pipeline step to the company RAG system.

## The pipeline

`KnowledgeIngestor` turns a `KnowledgeResource` into `KnowledgePage`, then `KnowledgeChunk` via `TextChunker`, then `KnowledgeFact`.
Embeddings come from `EmbeddingService`, shared with flow memory.

## Steps

1. For a new source type, add its ingest path and resource handling in `app/Services/Knowledge/`.
2. Extract text with `DocumentTextExtractor` for files, or `CrawlService` plus `WebPageCacheService` for URLs.
3. Run ingest asynchronously through `IngestResourceJob` or `IngestUrlResourceJob`.
4. Reuse `EmbeddingService` for vectors; do not add a second embedding path.

## Guardrails

- URL ingest caches through `web_page_cache` and `web_page_digests`.
- The planner treats company knowledge as supplementary; research agents keep their web tools.
- Gaps, conflicts, and facts are modeled separately; route each to its own service.

## Verify

- `php -l` on changed files.
- Do not run tests or eval suites.
