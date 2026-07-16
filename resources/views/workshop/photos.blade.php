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
            <div class="grid grid-cols-2 gap-3">
                <label class="block cursor-pointer rounded-lg border-2 border-dashed border-slate-600 bg-slate-800 px-3 py-7 text-center transition hover:border-sky-500">
                    <span class="block font-semibold">Tomar foto</span>
                    <span class="mt-1 block text-xs text-slate-400">Abrir cámara</span>
                    <input id="camera" class="sr-only" type="file" accept="image/*" capture="environment">
                </label>
                <label class="block cursor-pointer rounded-lg border-2 border-dashed border-slate-600 bg-slate-800 px-3 py-7 text-center transition hover:border-sky-500">
                    <span class="block font-semibold">Elegir fotos</span>
                    <span class="mt-1 block text-xs text-slate-400">Seleccionar varias</span>
                    <input id="gallery" class="sr-only" type="file" accept="image/*" multiple>
                </label>
            </div>
            <p class="mt-2 text-center text-xs text-slate-500">HEIC, JPG, PNG o WebP · máximo 20 MB</p>
            <div id="previews" class="mt-4 hidden grid grid-cols-2 gap-3"></div>
            <div id="selection" class="mt-3 hidden rounded-md bg-slate-800 px-3 py-2 text-sm text-slate-300"></div>
            <button id="submit" class="mt-4 h-12 w-full rounded-md bg-sky-600 font-semibold text-white transition hover:bg-sky-500 disabled:opacity-50" type="submit" disabled>Subir fotos</button>
        </form>
        <p id="status" class="mt-4 rounded-md bg-emerald-950 px-3 py-2 text-sm text-emerald-300">{{ $photoCount }} fotos guardadas.</p>
        <p class="mt-4 text-center text-xs text-slate-500">Este enlace es temporal y solo permite agregar fotografías a esta orden.</p>
    </section>
</main>
<script>
const camera = document.getElementById('camera');
const gallery = document.getElementById('gallery');
const form = document.getElementById('photo-form');
const button = document.getElementById('submit');
const selection = document.getElementById('selection');
const status = document.getElementById('status');
const previews = document.getElementById('previews');
let selectedFiles = [];
function addFiles(files) {
    selectedFiles = [...selectedFiles, ...Array.from(files)].slice(0, 10);
    renderSelection();
    camera.value = ''; gallery.value = '';
}
function renderSelection() {
    const count = selectedFiles.length;
    selection.textContent = count ? `${count} foto${count === 1 ? '' : 's'} seleccionada${count === 1 ? '' : 's'}.` : '';
    selection.classList.toggle('hidden', !count);
    previews.classList.toggle('hidden', !count);
    previews.replaceChildren();
    selectedFiles.forEach((file, index) => {
        const card = document.createElement('div'); card.className = 'relative aspect-[4/3] overflow-hidden rounded-md bg-slate-800';
        const image = document.createElement('img'); image.src = URL.createObjectURL(file); image.alt = file.name; image.className = 'h-full w-full object-contain'; image.onload = () => URL.revokeObjectURL(image.src); image.onerror = () => { image.remove(); const fileName = document.createElement('span'); fileName.textContent = file.name; fileName.className = 'p-2 text-center text-xs text-slate-400'; card.prepend(fileName); };
        const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Quitar'; remove.className = 'absolute right-1 top-1 rounded-md bg-red-600 px-2 py-1 text-xs font-semibold text-white'; remove.addEventListener('click', () => { selectedFiles.splice(index, 1); renderSelection(); });
        card.append(image, remove); previews.append(card);
    });
    button.disabled = !count;
}
camera.addEventListener('change', () => addFiles(camera.files));
gallery.addEventListener('change', () => addFiles(gallery.files));
form.addEventListener('submit', async (event) => {
    event.preventDefault(); button.disabled = true; button.textContent = 'Subiendo…';
    try {
        const data = new FormData(); selectedFiles.forEach(file => data.append('photos[]', file, file.name));
        const response = await fetch(@json('/api/v1/workshop/photo-upload/'.$token), { method: 'POST', body: data, headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'No fue posible subir las fotos.');
        status.textContent = `${result.total} fotos guardadas.`; status.className = 'mt-4 rounded-md bg-emerald-950 px-3 py-2 text-sm text-emerald-300';
        selectedFiles = []; form.reset(); renderSelection();
    } catch (error) {
        status.textContent = error.message; status.className = 'mt-4 rounded-md bg-red-950 px-3 py-2 text-sm text-red-300';
    } finally { button.textContent = 'Subir fotos'; button.disabled = !selectedFiles.length; }
});
</script>
</body>
</html>
