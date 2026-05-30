{{-- resources/views/mail/flow-run-report.blade.php --}}
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: -apple-system, sans-serif; background: #f4f4f5; margin: 0; padding: 24px; }
  .wrap { max-width: 680px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  .header { background: #4f46e5; padding: 28px 32px; }
  .header h1 { color: #fff; margin: 0; font-size: 20px; font-weight: 700; }
  .header p  { color: #c7d2fe; margin: 4px 0 0; font-size: 14px; }
  .body { padding: 32px; color: #1e293b; font-size: 15px; line-height: 1.7; }
  .body h1,.body h2,.body h3 { color: #1e293b; }
  .body table { width: 100%; border-collapse: collapse; margin: 16px 0; }
  .body th { background: #f1f5f9; text-align: left; padding: 8px 12px; font-size: 13px; }
  .body td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
  .footer { padding: 20px 32px; background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b; }
  .footer a { color: #4f46e5; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>{{ $flowName }}</h1>
    <p>Автоматичен репорт — {{ now()->format('d.m.Y') }}</p>
  </div>
  <div class="body">
    {!! Str::markdown($reportContent) !!}
  </div>
  <div class="footer">
    Генериран от <strong>FlowAI</strong> &nbsp;·&nbsp; {{ now()->format('H:i') }}
    &nbsp;·&nbsp; <a href="{{ url('/runs/' . $flowRunId) }}">Виж в приложението</a>
  </div>
</div>
</body>
</html>
