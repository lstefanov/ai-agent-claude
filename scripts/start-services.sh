#!/bin/bash
# =============================================================================
#  FlowAI — пълен стартов скрипт за всички услуги
# =============================================================================
#
#  Цел: след рестарт на компютъра една команда вдига ЦЕЛИЯ стек:
#
#      ./scripts/start-services.sh          # стартира всичко
#      ./scripts/start-services.sh stop     # спира всичко, пуснато от скрипта
#      ./scripts/start-services.sh status   # показва кое върви и кое — не
#
#  Всяка услуга се пуска във ФОН (с &), логва в /tmp/<име>.log и се проверява
#  с health-check. Скриптът е идемпотентен — ако нещо вече върви, не го дублира.
#
# -----------------------------------------------------------------------------
#  УСЛУГИТЕ (какво е всяка и защо ни трябва)
# -----------------------------------------------------------------------------
#
#  1) Ollama            :11434
#     Локален двигател за LLM inference. ПРЕЗ него минава цялото "мислене" на
#     агентите — генериране на текст, разсъждения, синтез. Обвит е в
#     OllamaService / ModelSelectorService. Без Ollama НИТО един агент не може
#     да произведе изход и всеки flow ще се проваля.
#
#  2) ComfyUI           :8188
#     Локална генерация на изображения (Stable Diffusion). Ползва се от
#     ImagePromptAgent чрез ComfyUIService, когато даден агент трябва да създаде
#     картинка. Не е нужен за текстовите flow-ове, но без него image-стъпките
#     се пропускат/провалят.
#
#  3) Crawl4AI scraper  :8189   (Python FastAPI, scripts/crawl_service.py)
#     Микросървис, който отваря подаден URL в истински headless браузър и връща
#     чист Markdown. Laravel-ската CrawlService праща заявки към /scrape, а
#     WebScraperTool / SiteCrawlerTool / site-analysis pipeline-ът го ползват за
#     анализ на сайтове. БЕЗ него scrape/crawl връща празно (точно това е
#     грешката "scrape microservice (:8189) was DOWN").
#
#  4) Laravel web server :8000  (php artisan serve)
#     HTTP сървърът на самото приложение (UI + AJAX + webhook endpoint-ите).
#     Забележка: APP_URL е http://flowai.local — ако ползваш MAMP PRO vhost,
#     сайтът се обслужва и оттам; artisan serve на :8000 е алтернативният
#     dev достъп, който composer dev също вдига.
#
#  5) Queue workers      (опашки: flows + default)
#     Опашката е database-backed, затова БЕЗ работещ worker нито един flow не
#     тръгва. Агентските задачи (ExecuteAgentJob и т.н.) се пускат на опашка
#     'flows'; scheduler/webhook задачите (ExecuteFlowJob, SyncOllamaModelsJob)
#     отиват на 'default'. Затова стартираме И flows worker(и), И listener за
#     всички опашки — точно както прави composer dev.
#
#  6) Vite dev server    :5173  (npm run dev)
#     Frontend dev сървърът (Tailwind v4 + JS, с HMR). Обслужва компилираните
#     asset-и за Blade изгледите по време на разработка. Алтернатива: еднократно
#     `npm run build` (статични asset-и, без работещ процес).
#
# =============================================================================

# --- НЕ слагаме `set -e`: искаме ако една услуга не тръгне, ОСТАНАЛИТЕ пак да
#     се вдигнат. Всяка услуга се guard-ва поотделно.

# -----------------------------------------------------------------------------
#  Пътища и binaries
# -----------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
CRAWL_VENV="$SCRIPT_DIR/.venv"
COMFY_DIR="$HOME/ComfyUI"
LOG_DIR="/tmp"

# PHP се фиксира експлицитно към 8.2.0 (MAMP). Така скриптът не зависи от това
# дали `php` alias-ът/PATH-ът са заредени (подпроцесите на composer dev иначе
# хващаха php7.3 и гърмяха с exit 255).
PHP_BIN="/Applications/MAMP/bin/php/php8.2.0/bin/php"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php)"
ARTISAN="$PROJECT_DIR/artisan"

# -----------------------------------------------------------------------------
#  Помощни функции
# -----------------------------------------------------------------------------
http_ok()        { curl -s -o /dev/null -m 3 "$1" 2>/dev/null; }   # 200/любой отговор
port_listening() { lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1; }
running()        { pgrep -f "$1" >/dev/null 2>&1; }

# -----------------------------------------------------------------------------
#  stop / status подкоманди
# -----------------------------------------------------------------------------
stop_all() {
    echo "🛑 Спиране на услугите, пуснати от този скрипт..."
    # Спираме само нашите процеси. Ollama НЕ го пипаме — той е споделен daemon.
    pkill -f "uvicorn crawl_service:app"          2>/dev/null && echo "  ✓ Crawl4AI спрян"
    pkill -f "artisan serve"                      2>/dev/null && echo "  ✓ Web server спрян"
    pkill -f "artisan queue:work"                 2>/dev/null && echo "  ✓ queue:work спрян"
    pkill -f "artisan queue:listen"               2>/dev/null && echo "  ✓ queue:listen спрян"
    pkill -f "$COMFY_DIR/main.py"                 2>/dev/null && echo "  ✓ ComfyUI спрян"
    pkill -f "vite"                               2>/dev/null && echo "  ✓ Vite спрян"
    echo "ℹ️  Ollama НЕ е спиран (споделен daemon). За да го спреш: pkill -x ollama"
    exit 0
}

status_all() {
    echo "📊 Статус на услугите:"
    http_ok "http://localhost:11434/api/tags"      && echo "  ✓ Ollama        :11434" || echo "  ✗ Ollama        :11434"
    http_ok "http://localhost:8188/system_stats"   && echo "  ✓ ComfyUI       :8188"  || echo "  ✗ ComfyUI       :8188"
    http_ok "http://localhost:8189/health"         && echo "  ✓ Crawl4AI      :8189"  || echo "  ✗ Crawl4AI      :8189"
    http_ok "http://localhost:8000"                && echo "  ✓ Web server    :8000"  || echo "  ✗ Web server    :8000"
    running "artisan queue:work.*flows"            && echo "  ✓ Queue (flows)"        || echo "  ✗ Queue (flows)"
    running "artisan queue:listen"                 && echo "  ✓ Queue (default)"      || echo "  ✗ Queue (default)"
    port_listening 5173                            && echo "  ✓ Vite          :5173"  || echo "  ✗ Vite          :5173"
    exit 0
}

case "$1" in
    stop)   stop_all ;;
    status) status_all ;;
esac

# =============================================================================
#  СТАРТ
# =============================================================================
echo "🚀 Стартиране на FlowAI услугите..."
echo "   PHP:     $PHP_BIN"
echo "   Проект:  $PROJECT_DIR"
echo ""

# -----------------------------------------------------------------------------
#  1) Ollama  (:11434) — LLM inference
# -----------------------------------------------------------------------------
if running "ollama serve" || http_ok "http://localhost:11434/api/tags"; then
    echo "✓ Ollama вече върви"
elif ! command -v ollama >/dev/null 2>&1; then
    echo "✗ Ollama не е инсталиран (липсва бинарникът 'ollama') — пропуснат"
else
    echo "Стартиране на Ollama..."
    ollama serve >"$LOG_DIR/ollama.log" 2>&1 &
    sleep 2
    http_ok "http://localhost:11434/api/tags" \
        && echo "✓ Ollama стартиран" \
        || echo "✗ Ollama не тръгна — виж $LOG_DIR/ollama.log"
fi

# -----------------------------------------------------------------------------
#  2) ComfyUI  (:8188) — генерация на изображения
# -----------------------------------------------------------------------------
if http_ok "http://localhost:8188/system_stats"; then
    echo "✓ ComfyUI вече върви"
elif [ ! -f "$COMFY_DIR/venv/bin/activate" ]; then
    echo "✗ ComfyUI не е намерен в $COMFY_DIR — пропуснат"
else
    echo "Стартиране на ComfyUI..."
    # Subshell — за да не сменяме работната директория на самия скрипт.
    (
        cd "$COMFY_DIR" || exit 1
        # shellcheck disable=SC1091
        source venv/bin/activate
        python main.py --listen 127.0.0.1 --port 8188 >"$LOG_DIR/comfyui.log" 2>&1 &
    )
    # Първото пускане зарежда модели — чакаме до ~30s.
    for i in $(seq 1 6); do
        sleep 5
        if http_ok "http://localhost:8188/system_stats"; then
            echo "✓ ComfyUI стартиран"
            break
        fi
        echo "  Чакам ComfyUI... (${i}/6)"
    done
    http_ok "http://localhost:8188/system_stats" \
        || echo "✗ ComfyUI не тръгна — виж $LOG_DIR/comfyui.log"
fi

# -----------------------------------------------------------------------------
#  3) Crawl4AI scraper  (:8189) — scrape микросървис
# -----------------------------------------------------------------------------
if http_ok "http://localhost:8189/health"; then
    echo "✓ Crawl4AI вече върви"
elif [ ! -x "$CRAWL_VENV/bin/uvicorn" ]; then
    echo "✗ Crawl4AI venv липсва в $CRAWL_VENV"
    echo "  Създай го: python3 -m venv scripts/.venv && scripts/.venv/bin/pip install crawl4ai fastapi uvicorn"
else
    echo "Стартиране на Crawl4AI..."
    "$CRAWL_VENV/bin/uvicorn" crawl_service:app \
        --host 0.0.0.0 --port 8189 \
        --app-dir "$SCRIPT_DIR" \
        >"$LOG_DIR/crawl4ai.log" 2>&1 &
    sleep 3
    http_ok "http://localhost:8189/health" \
        && echo "✓ Crawl4AI стартиран" \
        || echo "✗ Crawl4AI не тръгна — виж $LOG_DIR/crawl4ai.log"
fi

# -----------------------------------------------------------------------------
#  4) Laravel web server  (:8000)
# -----------------------------------------------------------------------------
if port_listening 8000; then
    echo "✓ Web server (:8000) вече върви"
else
    echo "Стартиране на Laravel web server..."
    "$PHP_BIN" "$ARTISAN" serve >"$LOG_DIR/laravel-serve.log" 2>&1 &
    sleep 2
    port_listening 8000 \
        && echo "✓ Web server стартиран → http://localhost:8000" \
        || echo "✗ Web server не тръгна — виж $LOG_DIR/laravel-serve.log"
fi

# -----------------------------------------------------------------------------
#  5) Queue workers — flows (агенти) + default (scheduler/webhook)
# -----------------------------------------------------------------------------
# 5a) Два worker-а за опашка 'flows' (паралелно изпълнение на агенти).
if running "artisan queue:work.*flows"; then
    echo "✓ Queue worker(и) за 'flows' вече вървят"
else
    echo "Стартиране на queue worker-и за 'flows'..."
    for n in 1 2; do
        "$PHP_BIN" "$ARTISAN" queue:work --queue=flows --tries=1 --timeout=0 \
            >"$LOG_DIR/queue-flows-$n.log" 2>&1 &
    done
    sleep 1
    running "artisan queue:work.*flows" \
        && echo "✓ Queue 'flows' worker-и стартирани (2 бр.)" \
        || echo "✗ Queue 'flows' не тръгна — виж $LOG_DIR/queue-flows-*.log"
fi

# 5b) Listener за останалите опашки (default) — scheduler/webhook задачи.
if running "artisan queue:listen"; then
    echo "✓ Queue listener (default) вече върви"
else
    echo "Стартиране на queue listener (default)..."
    "$PHP_BIN" "$ARTISAN" queue:listen --tries=1 --timeout=0 \
        >"$LOG_DIR/queue-default.log" 2>&1 &
    sleep 1
    running "artisan queue:listen" \
        && echo "✓ Queue listener стартиран" \
        || echo "✗ Queue listener не тръгна — виж $LOG_DIR/queue-default.log"
fi

# -----------------------------------------------------------------------------
#  6) Vite dev server  (:5173)
# -----------------------------------------------------------------------------
if port_listening 5173; then
    echo "✓ Vite (:5173) вече върви"
elif [ ! -d "$PROJECT_DIR/node_modules" ]; then
    echo "✗ node_modules липсва — изпълни: npm install"
else
    echo "Стартиране на Vite dev server..."
    ( cd "$PROJECT_DIR" && npm run dev >"$LOG_DIR/vite.log" 2>&1 & )
    sleep 3
    port_listening 5173 \
        && echo "✓ Vite стартиран → http://localhost:5173" \
        || echo "✗ Vite не тръгна — виж $LOG_DIR/vite.log"
fi

# =============================================================================
#  Резюме
# =============================================================================
echo ""
echo "──────────────────────────────────────────────"
echo "Услуги:"
echo "  Ollama:        http://localhost:11434   ($LOG_DIR/ollama.log)"
echo "  ComfyUI:       http://localhost:8188    ($LOG_DIR/comfyui.log)"
echo "  Crawl4AI:      http://localhost:8189    ($LOG_DIR/crawl4ai.log)"
echo "  Web server:    http://localhost:8000    ($LOG_DIR/laravel-serve.log)"
echo "  Queue flows:   $LOG_DIR/queue-flows-*.log"
echo "  Queue default: $LOG_DIR/queue-default.log"
echo "  Vite:          http://localhost:5173    ($LOG_DIR/vite.log)"
echo "  FlowAI (vhost):http://flowai.local      (MAMP PRO, ако е конфигуриран)"
echo "──────────────────────────────────────────────"
echo ""
echo "Всички услуги вървят във ФОН и остават след затваряне на терминала."
echo "  Статус:  ./scripts/start-services.sh status"
echo "  Спиране: ./scripts/start-services.sh stop"
echo "  Логове:  tail -f $LOG_DIR/<име>.log"
