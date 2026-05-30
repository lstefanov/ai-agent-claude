#!/bin/bash
# Start Ollama + ComfyUI + Crawl4AI for FlowAI development

set -e

echo "🚀 Starting FlowAI services..."

# Start Ollama
if pgrep -x "ollama" > /dev/null; then
    echo "✓ Ollama already running"
else
    echo "Starting Ollama..."
    ollama serve &> /tmp/ollama.log &
    OLLAMA_PID=$!
    sleep 2
    if curl -s http://localhost:11434/api/tags > /dev/null; then
        echo "✓ Ollama started (PID: $OLLAMA_PID)"
    else
        echo "✗ Ollama failed to start — check /tmp/ollama.log"
    fi
fi

# Start ComfyUI
if curl -s http://localhost:8188/system_stats > /dev/null 2>&1; then
    echo "✓ ComfyUI already running"
else
    echo "Starting ComfyUI..."
    cd ~/ComfyUI
    source venv/bin/activate
    python main.py --listen 127.0.0.1 --port 8188 &> /tmp/comfyui.log &
    COMFY_PID=$!
    # Wait up to 30s for ComfyUI to be ready (first run loads model)
    for i in $(seq 1 6); do
        sleep 5
        if curl -s http://localhost:8188/system_stats > /dev/null 2>&1; then
            echo "✓ ComfyUI started (PID: $COMFY_PID)"
            break
        fi
        echo "  Waiting for ComfyUI... (${i}/6)"
    done
    if ! curl -s http://localhost:8188/system_stats > /dev/null 2>&1; then
        echo "✗ ComfyUI failed to start — check /tmp/comfyui.log"
    fi
fi

# Start Crawl4AI scraping service
CRAWL_VENV="$(dirname "$0")/.venv"
if curl -s http://localhost:8189/health > /dev/null 2>&1; then
    echo "✓ Crawl4AI already running"
else
    echo "Starting Crawl4AI..."
    if [ ! -f "$CRAWL_VENV/bin/uvicorn" ]; then
        echo "✗ Crawl4AI venv not found at $CRAWL_VENV — run: pip install crawl4ai fastapi uvicorn"
    else
        "$CRAWL_VENV/bin/uvicorn" crawl_service:app \
            --host 0.0.0.0 --port 8189 \
            --app-dir "$(dirname "$0")" \
            &> /tmp/crawl4ai.log &
        CRAWL_PID=$!
        sleep 3
        if curl -s http://localhost:8189/health > /dev/null 2>&1; then
            echo "✓ Crawl4AI started (PID: $CRAWL_PID)"
        else
            echo "✗ Crawl4AI failed to start — check /tmp/crawl4ai.log"
        fi
    fi
fi

echo ""
echo "Services:"
echo "  Ollama:   http://localhost:11434"
echo "  ComfyUI:  http://localhost:8188"
echo "  Crawl4AI: http://localhost:8189"
echo "  FlowAI:   http://flowai.local"
echo ""
echo "Press Ctrl+C to stop background processes (if started by this script)."
