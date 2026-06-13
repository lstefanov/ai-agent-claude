import asyncio
import os
from fastapi import FastAPI
from pydantic import BaseModel

app = FastAPI()


class ScrapeRequest(BaseModel):
    url: str
    timeout: int = 35


def _extract_meta(result, page_url: str):
    """Title, meta description and same-host internal links from the RENDERED
    page (crawl4ai exposes them post-JS, so SPA menus/pagination are visible).
    Links come back as absolute hrefs; the PHP side normalizes + filters."""
    meta = getattr(result, "metadata", None) or {}
    title = (meta.get("title") or meta.get("og:title") or "").strip()
    description = (meta.get("description") or meta.get("og:description") or "").strip()

    links = []
    raw_links = getattr(result, "links", None) or {}
    if isinstance(raw_links, dict):
        for item in raw_links.get("internal", []) or []:
            if isinstance(item, dict):
                href = item.get("href")
            elif isinstance(item, str):
                href = item
            else:
                href = getattr(item, "href", None)
            if isinstance(href, str) and href.strip():
                links.append(href.strip())

    # De-dup, keep order, hard-cap so pathological pages can't bloat the payload.
    seen = set()
    unique_links = []
    for href in links:
        if href not in seen:
            seen.add(href)
            unique_links.append(href)
        if len(unique_links) >= 500:
            break

    return title, description, unique_links


def _extract_markdown(result) -> str:
    """crawl4ai returns markdown either as a plain string or as a
    MarkdownGenerationResult object. Prefer fit_markdown, then raw markdown,
    then fall back to cleaned HTML so JS-heavy pages don't come back empty."""
    md = getattr(result, "markdown", None)

    if md is not None and not isinstance(md, str):
        text = (getattr(md, "fit_markdown", None) or getattr(md, "raw_markdown", None) or "")
        if isinstance(text, str) and text.strip():
            return text

    if isinstance(md, str) and md.strip():
        return md

    # Last-resort fallbacks for pages whose markdown generator yielded nothing.
    for attr in ("fit_markdown", "cleaned_html"):
        val = getattr(result, attr, None)
        if isinstance(val, str) and val.strip():
            return val

    return ""


async def _crawl(url: str, timeout: int):
    """Render the page in a real headless browser, waiting for JS-loaded content.

    Many sites (incl. JS storefronts) return near-empty markdown without waiting
    for network idle + a short settle delay — that is why the homepage and the
    /p/ product/price pages came back as 1-4 chars. We wait for the page to
    settle, scroll the full page to trigger lazy loading, and retry once with a
    longer, more aggressive wait if the first pass yields empty markdown."""
    from crawl4ai import AsyncWebCrawler, CrawlerRunConfig, CacheMode

    page_timeout_ms = max(15000, timeout * 1000)

    base_kwargs = dict(
        cache_mode=CacheMode.BYPASS,
        page_timeout=page_timeout_ms,
        scan_full_page=True,
        word_count_threshold=1,  # keep short blocks (prices/contacts) instead of filtering them out
    )

    async with AsyncWebCrawler(headless=True, verbose=False) as crawler:
        # Pass 1 — fast: DOM ready + a short settle delay for JS-rendered content.
        # (networkidle can hang up to page_timeout on pages with persistent
        # connections — e.g. analytics — which made empty pages very slow.)
        cfg = CrawlerRunConfig(
            wait_until="domcontentloaded",
            delay_before_return_html=2.5,
            **base_kwargs,
        )
        result = await crawler.arun(url=url, config=cfg)
        markdown = _extract_markdown(result)
        if markdown.strip():
            return result, markdown

        # Pass 2 — only for pages that came back empty: wait for full load + magic
        # mode (handles overlays/consent walls that hide content on first paint).
        cfg2 = CrawlerRunConfig(
            wait_until="load",
            delay_before_return_html=3.5,
            magic=True,
            **base_kwargs,
        )
        result2 = await crawler.arun(url=url, config=cfg2)
        markdown2 = _extract_markdown(result2)
        if markdown2.strip():
            return result2, markdown2

        return result, markdown


@app.post("/scrape")
async def scrape(request: ScrapeRequest):
    try:
        # Allow the two-pass crawl extra wall-clock room over the per-page timeout.
        result, markdown = await asyncio.wait_for(
            _crawl(request.url, request.timeout),
            timeout=request.timeout * 2 + 10,
        )
        title, description, links = _extract_meta(result, request.url)
        return {
            "url": request.url,
            "markdown": markdown or "",
            "title": title,
            "meta_description": description,
            "internal_links": links,
            "success": bool(getattr(result, "success", False)),
            "status_code": getattr(result, "status_code", 200) or 200,
        }
    except asyncio.TimeoutError:
        return {"url": request.url, "markdown": "", "title": "", "meta_description": "",
                "internal_links": [], "success": False, "status_code": 408}
    except Exception:
        return {"url": request.url, "markdown": "", "title": "", "meta_description": "",
                "internal_links": [], "success": False, "status_code": 500}


@app.get("/health")
async def health():
    return {"status": "ok", "crawl4ai": "ready"}


if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("CRAWL_PORT", 8189))
    uvicorn.run(app, host="0.0.0.0", port=port)
