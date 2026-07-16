<?php

namespace App\Http\Controllers;

use App\Models\WorkshopOrderPhoto;
use App\Models\WorkshopPhotoSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PublicWorkshopPhotoController extends Controller
{
    public function show(string $token): View
    {
        $session = $this->session($token);
        $session->load('order.device.customer');

        return view('workshop.photos', [
            'token' => $token,
            'order' => $session->order,
            'photoCount' => WorkshopOrderPhoto::query()->where('workshop_order_id', $session->workshop_order_id)->count(),
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $session = $this->session($token);
        $data = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
        ]);
        $created = [];
        foreach ($data['photos'] as $photo) {
            $extension = strtolower($photo->guessExtension() ?: 'jpg');
            $path = $photo->storeAs("workshop/{$session->tenant_id}/{$session->workshop_order_id}/reception", Str::uuid().'.'.$extension, 'local');
            abort_if($path === false, 500, 'No fue posible almacenar la fotografía.');
            $created[] = WorkshopOrderPhoto::query()->create([
                'tenant_id' => $session->tenant_id, 'workshop_order_id' => $session->workshop_order_id,
                'disk' => 'local', 'path' => $path, 'original_name' => $photo->getClientOriginalName(),
                'mime_type' => $photo->getMimeType() ?: 'application/octet-stream', 'size' => $photo->getSize(),
                'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'stage' => 'reception',
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
