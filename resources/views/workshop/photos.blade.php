<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fotos · {{ $order->ticket_number }}</title>
    @php
        $manifestPath = public_path('build/manifest.json');
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
        $appCss = $manifest['resources/js/app.js']['css'][0] ?? null;
    @endphp
    @if($appCss)
        <link rel="stylesheet" href="{{ asset('build/'.$appCss) }}">
    @endif
    <style>
        body { margin: 0; font-family: ui-sans-serif, system-ui, sans-serif; }
        button, input { font: inherit; }
    </style>
</head>
<body class="min-h-full bg-slate-950 text-slate-100">
<main class="mx-auto flex min-h-screen max-w-xl items-center px-4 py-8">
    <section class="w-full rounded-xl border border-slate-700 bg-slate-900 p-5 shadow-2xl">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-400">Taller electrónico</p>
        <h1 class="mt-2 text-2xl font-bold">Fotos de recepción</h1>
        <p class="mt-2 text-sm text-slate-400">T-{{ str_pad((string) $order->ticket_number, 6, '0', STR_PAD_LEFT) }} · {{ $order->device->brand }} {{ $order->device->model }}</p>
        <form id="photo-form" class="mt-6">
            <label class="block cursor-pointer rounded-lg border-2 border-dashed border-slate-600 bg-slate-800 px-4 py-10 text-center transition hover:border-sky-500">
                <span class="block font-semibold">Tomar o seleccionar fotos</span>
                <span class="mt-1 block text-sm text-slate-400">JPG, PNG o WebP · máximo 10 MB por foto</span>
                <input id="photos" class="sr-only" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple>
            </label>
            <div id="selection" class="mt-3 hidden rounded-md bg-slate-800 px-3 py-2 text-sm text-slate-300"></div>
            <button id="submit" class="mt-4 h-12 w-full rounded-md bg-sky-600 font-semibold text-white transition hover:bg-sky-500 disabled:opacity-50" type="submit" disabled>Subir fotos</button>
        </form>
        <p id="status" class="mt-4 rounded-md bg-emerald-950 px-3 py-2 text-sm text-emerald-300">{{ $photoCount }} fotos guardadas.</p>
        <p class="mt-4 text-center text-xs text-slate-500">Este enlace es temporal y solo permite agregar fotografías a esta orden.</p>
    </section>
</main>
<script>
const input = document.getElementById('photos');
const form = document.getElementById('photo-form');
const button = document.getElementById('submit');
const selection = document.getElementById('selection');
const status = document.getElementById('status');
input.addEventListener('change', () => {
    const count = input.files.length;
    selection.textContent = count ? `${count} foto${count === 1 ? '' : 's'} seleccionada${count === 1 ? '' : 's'}.` : '';
    selection.classList.toggle('hidden', !count);
    button.disabled = !count;
});
form.addEventListener('submit', async (event) => {
    event.preventDefault(); button.disabled = true; button.textContent = 'Subiendo…';
    try {
        const response = await fetch(@json('/api/v1/workshop/photo-upload/'.$token), { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'No fue posible subir las fotos.');
        status.textContent = `${result.total} fotos guardadas.`; status.className = 'mt-4 rounded-md bg-emerald-950 px-3 py-2 text-sm text-emerald-300';
        form.reset(); selection.classList.add('hidden');
    } catch (error) {
        status.textContent = error.message; status.className = 'mt-4 rounded-md bg-red-950 px-3 py-2 text-sm text-red-300';
    } finally { button.textContent = 'Subir fotos'; button.disabled = !input.files.length; }
});
</script>
</body>
</html>
