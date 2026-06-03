# FlowAI — Свързване с Ollama на Windows сървър

## Контекст

Проектът работи на Mac за разработка, но Ollama и всички LLM модели се намират на Windows машина в локалната мрежа. Тази страница описва какво трябва да се промени.

---

## Стъпка 1: Конфигурирай Ollama на Windows да приема външни връзки

По подразбиране Ollama слуша само на `127.0.0.1` — трябва да го накараш да слуша на всички интерфейси.

### Чрез системна променлива (препоръчан начин)

1. Отвори **Start → Search → "Edit the system environment variables"**
2. Натисни **"Environment Variables..."**
3. В **System variables** → **New**:
   - Variable name: `OLLAMA_HOST`
   - Variable value: `0.0.0.0:11434`
4. **OK** навсякъде
5. Рестартирай Ollama (или целия компютър)

### Проверка

От Mac-а провери дали Ollama отговаря:

```bash
curl http://<WINDOWS_IP>:11434/api/tags
```

Трябва да получиш JSON с инсталираните модели.

> **Намери Windows IP:** На Windows → `ipconfig` → търси `IPv4 Address` под активния адаптер (обикновено нещо като `192.168.1.xxx`)

---

## Стъпка 2: Промени `.env` на Mac (проекта)

Отвори `.env` в корена на проекта и промени само **един ред**:

```dotenv
# Преди (локален Ollama):
OLLAMA_URL=http://localhost:11434

# След (Windows сървър):
OLLAMA_URL=http://192.168.1.XXX:11434
```

Замени `192.168.1.XXX` с реалния IP на Windows машината.

> **Важно:** `PHP_CLI_BINARY` остава непроменен — пак сочи към PHP на Mac-а, защото PHP/Laravel вървят локално.

---

## Стъпка 3: Изчисти кеша на Laravel

След промяната в `.env` изпълни:

```bash
php artisan config:clear
php artisan cache:clear
```

---

## Стъпка 4: Тествай връзката от проекта

```bash
php artisan tinker
```

```php
app(\App\Services\OllamaService::class)->isAvailable();
// трябва да върне: true

app(\App\Services\OllamaService::class)->listModels();
// трябва да върне масив с модели
```

---

## Опционално: Статичен IP на Windows

За да не се налага да сменяш `.env` всеки път при рестарт, задай статичен IP на Windows машината:

**Settings → Network & Internet → Wi-Fi (или Ethernet) → Hardware properties → IP assignment → Edit → Manual → IPv4 On**

Пример:
- IP: `192.168.1.200`
- Subnet mask: `255.255.255.0`  
- Gateway: `192.168.1.1` (IP на рутера)
- DNS: `8.8.8.8`

След това актуализирай `.env` веднъж с този фиксиран IP.

---

## Firewall на Windows

Ако връзката не работи, може Windows Firewall да блокира порт `11434`.

**PowerShell (като Administrator):**

```powershell
New-NetFirewallRule -DisplayName "Ollama API" -Direction Inbound -Protocol TCP -LocalPort 11434 -Action Allow
```

---

## ComfyUI (ако го местиш също на Windows)

Ако ComfyUI също ще е на Windows, смени и:

```dotenv
COMFYUI_URL=http://192.168.1.XXX:8188
```

И аналогично настрой ComfyUI да слуша на `0.0.0.0:8188`:

```bash
# Стартирай ComfyUI с:
python main.py --listen 0.0.0.0 --port 8188
```

---

## Обобщение на промените в `.env`

| Ключ | Стара стойност | Нова стойност |
|------|---------------|---------------|
| `OLLAMA_URL` | `http://localhost:11434` | `http://192.168.1.XXX:11434` |
| `COMFYUI_URL` | `http://localhost:8188` | `http://192.168.1.XXX:8188` *(ако е нужно)* |

Всички останали настройки (`DB_*`, `PHP_CLI_BINARY`, `APP_*`) остават непроменени.
