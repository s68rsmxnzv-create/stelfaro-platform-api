<?php

namespace App\Http\Controllers;

use App\Models\WorkshopOrderPhoto;
use App\Models\WorkshopPhotoSession;
use App\Services\WorkshopPhotoProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicWorkshopPhotoController extends Controller
{
    public function show(string $token): View
    {
        $session = $this->session($token);
        $session->load('order.device.customer', 'order.photos');

        return view('workshop.photos', [
            'token' => $token,
            'order' => $session->order,
            'photos' => $session->order->photos->sortByDesc('created_at')->values(),
        ]);
    }

    public function photo(string $token, WorkshopOrderPhoto $photo): StreamedResponse
    {
        $session = $this->session($token);
        abort_unless($photo->tenant_id === $session->tenant_id && $photo->workshop_order_id === $session->workshop_order_id, 404);
        abort_unless(Storage::disk($photo->disk)->exists($photo->path), 404);

        return Storage::disk($photo->disk)->response($photo->path, $photo->original_name, [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline; filename="photo-'.$photo->id.'.jpg"',
        ]);
    }

    public function store(Request $request, string $token, WorkshopPhotoProcessor $processor): JsonResponse
    {
        $session = $this->session($token);
        $data = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,heic,heif', 'max:20480'],
        ]);
        $created = [];
        foreach ($data['photos'] as $photo) {
            $stored = $processor->store($photo, $session->tenant_id, $session->workshop_order_id);
            $created[] = WorkshopOrderPhoto::query()->create([
                'tenant_id' => $session->tenant_id, 'workshop_order_id' => $session->workshop_order_id,
                'disk' => 'local', 'path' => $stored['path'], 'original_name' => $photo->getClientOriginalName(),
                'mime_type' => $stored['mime_type'], 'size' => $stored['size'],
                'sha256' => $stored['sha256'], 'stage' => 'reception',
                'uploader_ip' => $request->ip(),
            ]);
        }

        return response()->json(['uploaded' => count($created), 'total' => WorkshopOrderPhoto::query()->where('workshop_order_id', $session->workshop_order_id)->count()], 201);
    }

    private function session(string $token): WorkshopPhotoSession
    {
        return WorkshopPhotoSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }
}
