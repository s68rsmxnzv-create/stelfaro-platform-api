<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Equipo · T-{{ str_pad((string) $order->ticket_number, 6, '0', STR_PAD_LEFT) }}</title>
    @php
        $manifestPath = public_path('build/manifest.json');
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
        $appCss = $manifest['resources/js/app.js']['css'][0] ?? null;
    @endphp
    @if($appCss)<link rel="stylesheet" href="{{ asset('build/'.$appCss) }}">@endif
</head>
<body class="min-h-full bg-slate-950 text-slate-100">
<main class="mx-auto flex min-h-screen max-w-lg items-center px-4 py-8">
    <section class="w-full overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
        <header class="border-b border-slate-700 p-5">
            <p class="text-xs font-bold uppercase tracking-widest text-sky-400">Equipo de taller</p>
            <h1 class="mt-2 text-3xl font-black">T-{{ str_pad((string) $order->ticket_number, 6, '0', STR_PAD_LEFT) }}</h1>
            <p class="mt-2 text-slate-300">{{ $device->brand }} {{ $device->model }}</p>
        </header>

        <div class="space-y-5 p-5">
            <div class="rounded-xl border border-slate-700 bg-slate-800 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Estado actual</p>
                <p id="status-label" class="mt-2 text-xl font-bold text-sky-300">{{ $statusLabels[$order->status] ?? $order->status }}</p>
            </div>

            <div id="locked-panel">
                <p class="text-sm leading-6 text-slate-400">Este acceso no requiere iniciar sesión. Ingresa el PIN del taller para administrar el equipo durante una sesión temporal de 15 minutos.</p>
                <form id="unlock-form" class="mt-4">
                    <label for="pin" class="text-sm font-semibold">PIN del taller</label>
                    <input id="pin" name="pin" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" class="mt-2 h-14 w-full rounded-xl border border-slate-600 bg-slate-950 px-4 text-center text-2xl font-black tracking-[0.35em] text-white outline-none focus:border-sky-500" placeholder="••••••" required>
                    <button id="unlock-button" type="submit" class="mt-3 h-12 w-full rounded-xl bg-sky-600 font-bold text-white transition hover:bg-sky-500 disabled:opacity-50">Abrir sesión segura</button>
                </form>
            </div>

            <div id="private-panel" class="hidden space-y-4">
                <div class="rounded-xl border border-emerald-800 bg-emerald-950/60 p-4 text-sm text-emerald-200">Sesión verificada. Se cerrará automáticamente en 15 minutos.</div>
                <div id="status-actions" class="grid gap-2"></div>
                @if($device->is_locked && filled($device->access_secret))
                    <button id="reveal-button" type="button" class="h-12 w-full rounded-xl border border-amber-600 bg-amber-950/40 font-bold text-amber-200 transition hover:bg-amber-950">Mostrar acceso del equipo</button>
                    <div id="secret-panel" class="hidden rounded-xl border border-amber-700 bg-slate-950 p-4 text-center">
                        <p id="secret-label" class="text-xs font-bold uppercase tracking-wide text-amber-300"></p>
                        <p id="secret-value" class="mt-3 break-all text-2xl font-black tracking-wider"></p>
                        <svg id="pattern-view" class="mx-auto mt-4 hidden h-52 w-52" viewBox="0 0 300 300" aria-label="Patrón de acceso"></svg>
                        <button id="hide-secret" type="button" class="mt-4 text-sm font-semibold text-slate-400">Ocultar acceso</button>
                    </div>
                @endif
            </div>

            <p id="message" class="hidden rounded-xl px-4 py-3 text-sm"></p>
            <p class="text-center text-xs text-slate-500">El uso de este acceso queda registrado por seguridad.</p>
        </div>
    </section>
</main>
<script nonce="{{ Vite::cspNonce() }}">
const publicToken = @json($token);
const storageKey = `stelfaro:workshop-device-session:${publicToken.slice(0, 12)}`;
const apiBase = `/api/v1/workshop/device-access/${encodeURIComponent(publicToken)}`;
const lockedPanel = document.getElementById('locked-panel');
const privatePanel = document.getElementById('private-panel');
const message = document.getElementById('message');
let sessionToken = sessionStorage.getItem(storageKey) || '';
let currentOrder = null;

function notify(text, error = false) {
    message.textContent = text;
    message.className = `rounded-xl px-4 py-3 text-sm ${error ? 'bg-red-950 text-red-200' : 'bg-emerald-950 text-emerald-200'}`;
}
function clearMessage() { message.className = 'hidden'; }
function expireSession(text = 'La sesión terminó. Ingresa nuevamente el PIN.') {
    sessionToken = ''; sessionStorage.removeItem(storageKey); currentOrder = null;
    privatePanel.classList.add('hidden'); lockedPanel.classList.remove('hidden'); notify(text, true);
}
async function api(path, options = {}) {
    const response = await fetch(`${apiBase}/${path}`, {
        ...options,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(sessionToken ? { 'X-Workshop-Session': sessionToken } : {}), ...(options.headers || {}) },
    });
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) { expireSession(); throw new Error(data.message || 'La sesión expiró.'); }
    if (!response.ok) throw new Error(data.message || 'No fue posible completar la acción.');
    return data;
}
function showPrivate(order) {
    currentOrder = order; lockedPanel.classList.add('hidden'); privatePanel.classList.remove('hidden');
    document.getElementById('status-label').textContent = order.status_label;
    const actions = document.getElementById('status-actions'); actions.innerHTML = '';
    order.next_statuses.forEach(next => {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'h-12 rounded-xl bg-sky-600 px-4 font-bold text-white transition hover:bg-sky-500'; button.textContent = next.label;
        button.addEventListener('click', () => updateStatus(next.value, button)); actions.appendChild(button);
    });
    if (!order.next_statuses.length) actions.innerHTML = '<p class="rounded-xl bg-slate-800 px-4 py-3 text-sm text-slate-400">No hay un cambio rápido disponible para este estado.</p>';
}
async function updateStatus(status, button) {
    clearMessage(); button.disabled = true;
    try { const data = await api('status', { method: 'PATCH', body: JSON.stringify({ status }) }); showPrivate(data.order); notify('Estado actualizado correctamente.'); }
    catch (error) { notify(error.message, true); button.disabled = false; }
}
document.getElementById('unlock-form').addEventListener('submit', async event => {
    event.preventDefault(); clearMessage(); const button = document.getElementById('unlock-button'); button.disabled = true;
    try {
        const data = await api('unlock', { method: 'POST', body: JSON.stringify({ pin: document.getElementById('pin').value }) });
        sessionToken = data.session_token; sessionStorage.setItem(storageKey, sessionToken); showPrivate(data.order); document.getElementById('pin').value = '';
    } catch (error) { notify(error.message, true); }
    finally { button.disabled = false; }
});
const revealButton = document.getElementById('reveal-button');
if (revealButton) revealButton.addEventListener('click', async () => {
    clearMessage(); revealButton.disabled = true;
    try {
        const data = await api('secret'); const panel = document.getElementById('secret-panel'); const pattern = document.getElementById('pattern-view'); const value = document.getElementById('secret-value');
        document.getElementById('secret-label').textContent = data.type === 'pattern' ? 'Patrón de acceso' : 'Código de acceso';
        if (data.type === 'pattern') { value.textContent = ''; drawPattern(data.secret, pattern); pattern.classList.remove('hidden'); }
        else { pattern.classList.add('hidden'); value.textContent = data.secret; }
        panel.classList.remove('hidden');
    } catch (error) { notify(error.message, true); }
    finally { revealButton.disabled = false; }
});
const hideSecret = document.getElementById('hide-secret');
if (hideSecret) hideSecret.addEventListener('click', () => { document.getElementById('secret-panel').classList.add('hidden'); document.getElementById('secret-value').textContent = ''; document.getElementById('pattern-view').innerHTML = ''; });
function drawPattern(secret, svg) {
    svg.innerHTML = ''; const points = String(secret).split('-').map(Number).filter(point => point >= 1 && point <= 9); const coordinate = point => ({ x: 50 + ((point - 1) % 3) * 100, y: 50 + Math.floor((point - 1) / 3) * 100 });
    if (points.length) { const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline'); line.setAttribute('points', points.map(point => { const p = coordinate(point); return `${p.x},${p.y}`; }).join(' ')); line.setAttribute('fill', 'none'); line.setAttribute('stroke', '#38bdf8'); line.setAttribute('stroke-width', '12'); line.setAttribute('stroke-linecap', 'round'); line.setAttribute('stroke-linejoin', 'round'); svg.appendChild(line); }
    for (let point = 1; point <= 9; point += 1) { const p = coordinate(point); const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle'); circle.setAttribute('cx', p.x); circle.setAttribute('cy', p.y); circle.setAttribute('r', points.includes(point) ? '18' : '10'); circle.setAttribute('fill', points.includes(point) ? '#38bdf8' : '#64748b'); svg.appendChild(circle); }
}
if (sessionToken) expireSession('Por seguridad, confirma nuevamente el PIN para iniciar una sesión nueva.');
</script>
</body>
</html>
