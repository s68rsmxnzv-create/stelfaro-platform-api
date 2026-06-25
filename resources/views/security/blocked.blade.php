<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Intento bloqueado | Stelfaro</title>
    <style>
        :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: Canvas; color: CanvasText; padding: 24px; }
        main { width: min(92vw, 640px); border: 1px solid color-mix(in srgb, CanvasText 14%, transparent); border-radius: 14px; padding: 32px; box-shadow: 0 18px 70px color-mix(in srgb, CanvasText 10%, transparent); }
        .badge { display: inline-flex; border-radius: 999px; background: #fee2e2; color: #b91c1c; padding: 8px 12px; font-size: 13px; font-weight: 800; }
        h1 { margin: 18px 0 12px; font-size: clamp(30px, 7vw, 46px); line-height: 1; letter-spacing: 0; }
        p { margin: 0; color: color-mix(in srgb, CanvasText 72%, transparent); font-size: 16px; line-height: 1.7; }
        .trace { margin-top: 22px; border-radius: 10px; background: color-mix(in srgb, CanvasText 7%, transparent); padding: 14px; font-size: 13px; font-weight: 700; color: color-mix(in srgb, CanvasText 70%, transparent); }
    </style>
</head>
<body>
    <main>
        <span class="badge">Solicitud bloqueada</span>
        <h1>{{ $message }}</h1>
        <p>{{ $details }}</p>
        <div class="trace">Evento de auditoría: {{ $event_id ?: 'registrado' }}</div>
    </main>
</body>
</html>
