import asyncio
import os
from fastapi import FastAPI
from pydantic import BaseModel

app = FastAPI()


class ScrapeRequest(BaseModel):
    url: str
    timeout: int = 15


@app.post("/scrape")
async def scrape(request: ScrapeRequest):
    from crawl4ai import AsyncWebCrawler

    try:
        async with AsyncWebCrawler(headless=True, verbose=False) as crawler:
            result = await asyncio.wait_for(
                crawler.arun(url=request.url),
                timeout=request.timeout,
            )
            return {
                "url": request.url,
                "markdown": result.markdown or "",
                "success": bool(result.success),
                "status_code": getattr(result, "status_code", 200) or 200,
            }
    except asyncio.TimeoutError:
        return {"url": request.url, "markdown": "", "success": False, "status_code": 408}
    except Exception as exc:
        return {"url": request.url, "markdown": "", "success": False, "status_code": 500}


@app.get("/health")
async def health():
    return {"status": "ok", "crawl4ai": "ready"}


if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("CRAWL_PORT", 8189))
    uvicorn.run(app, host="0.0.0.0", port=port)
