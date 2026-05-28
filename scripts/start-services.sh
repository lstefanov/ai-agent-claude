#!/bin/bash
# Start Ollama + ComfyUI for FlowAI development

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

echo ""
echo "Services:"
echo "  Ollama:   http://localhost:11434"
echo "  ComfyUI:  http://localhost:8188"
echo "  FlowAI:   http://flowai.local"
echo ""
echo "Press Ctrl+C to stop background processes (if started by this script)."
