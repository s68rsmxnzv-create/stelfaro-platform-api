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
            <label class="block cursor-pointer rounded-lg border-2 border-dashed border-slate-600 bg-slate-800 px-3 py-7 text-center transition hover:border-sky-500" for="photos">
                <span class="block font-semibold">Tomar o elegir foto</span>
                <span class="mt-1 block text-xs text-slate-400">Abre la cámara o selecciona una imagen</span>
            </label>
            <input id="photos" name="photos[]" class="sr-only" type="file" accept="image/*" capture="environment">
            <p class="mt-2 text-center text-xs text-slate-500">HEIC, JPG, PNG o WebP · máximo 20 MB</p>
            <div id="preview" class="mt-4 hidden">
                <div class="overflow-hidden rounded-lg bg-slate-800"><img id="preview-image" class="max-h-[60vh] w-full object-contain" alt="Vista previa de la foto"></div>
                <p id="selection" class="mt-3 rounded-md bg-slate-800 px-3 py-2 text-sm text-slate-300"></p>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <button id="retake" class="h-12 rounded-md border border-slate-600 bg-slate-800 font-semibold text-slate-100" type="button">Volver a tomar</button>
                    <button id="submit" class="h-12 rounded-md bg-sky-600 font-semibold text-white transition hover:bg-sky-500 disabled:opacity-50" type="submit">Subir foto</button>
                </div>
            </div>
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
const preview = document.getElementById('preview');
const previewImage = document.getElementById('preview-image');
const retake = document.getElementById('retake');
let processedBlob = null;

input.addEventListener('change', async () => {
    if (!input.files || !input.files.length) return;
    const file = input.files[0];
    button.disabled = true;
    selection.textContent = 'Preparando vista previa…';
    preview.classList.remove('hidden');
    try {
        const image = new Image();
        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = event => resolve(event.target.result);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
        processedBlob = await new Promise((resolve, reject) => {
            image.onload = () => {
                const canvas = document.createElement('canvas');
                const maximum = 2000;
                let width = image.naturalWidth || image.width;
                let height = image.naturalHeight || image.height;
                if (width > maximum || height > maximum) {
                    const ratio = Math.min(maximum / width, maximum / height);
                    width = Math.round(width * ratio); height = Math.round(height * ratio);
                }
                canvas.width = width; canvas.height = height;
                canvas.getContext('2d').drawImage(image, 0, 0, width, height);
                canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('No fue posible procesar la foto.')), 'image/jpeg', 0.88);
            };
            image.onerror = reject;
            image.src = dataUrl;
        });
        const transfer = new DataTransfer();
        transfer.items.add(new File([processedBlob], 'foto.jpg', { type: 'image/jpeg' }));
        input.files = transfer.files;
        previewImage.src = URL.createObjectURL(processedBlob);
        previewImage.onload = () => URL.revokeObjectURL(previewImage.src);
        selection.textContent = 'Foto lista para subir.';
    } catch (error) {
        processedBlob = file;
        previewImage.src = URL.createObjectURL(file);
        selection.textContent = 'Foto lista para subir.';
    }
    button.disabled = false;
});

retake.addEventListener('click', () => {
    input.value = ''; processedBlob = null; previewImage.removeAttribute('src'); preview.classList.add('hidden');
});

form.addEventListener('submit', event => {
    event.preventDefault();
    if (!input.files || !input.files.length) return;
    button.disabled = true; button.textContent = 'Subiendo…';
    const request = new XMLHttpRequest();
    request.open('POST', @json('/api/v1/workshop/photo-upload/'.$token));
    request.setRequestHeader('Accept', 'application/json');
    request.onload = () => {
        try {
            const result = JSON.parse(request.responseText || '{}');
            if (request.status < 200 || request.status >= 300) throw new Error(result.message || 'No fue posible subir la foto.');
            status.textContent = `${result.total} fotos guardadas.`; status.className = 'mt-4 rounded-md bg-emerald-950 px-3 py-2 text-sm text-emerald-300';
            form.reset(); processedBlob = null; preview.classList.add('hidden'); previewImage.removeAttribute('src');
        } catch (error) {
            status.textContent = error.message; status.className = 'mt-4 rounded-md bg-red-950 px-3 py-2 text-sm text-red-300';
        }
        button.textContent = 'Subir foto'; button.disabled = false;
    };
    request.onerror = () => {
        status.textContent = 'Error de conexión. Intenta nuevamente.'; status.className = 'mt-4 rounded-md bg-red-950 px-3 py-2 text-sm text-red-300';
        button.textContent = 'Subir foto'; button.disabled = false;
    };
    request.send(new FormData(form));
});
</script>
</body>
</html>
