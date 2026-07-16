<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkshopPhotoProcessor
{
    /** @return array{path:string,mime_type:string,size:int,sha256:string} */
    public function store(UploadedFile $photo, int $tenantId, int $orderId): array
    {
        $directory = "workshop/{$tenantId}/{$orderId}/reception";
        $mime = strtolower((string) $photo->getMimeType());
        $convert = in_array($mime, ['image/jpeg', 'image/heic', 'image/heif'], true);
        if ($convert && extension_loaded('imagick')) {
            $image = new \Imagick($photo->getRealPath());
            $image->setIteratorIndex(0);
            $image->autoOrient();
            if ($image->getImageWidth() > 2400 || $image->getImageHeight() > 2400) {
                $image->thumbnailImage(2400, 2400, true, true);
            }
            $image->setImageFormat('jpeg');
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality(88);
            $image->stripImage();
            $contents = $image->getImagesBlob();
            $image->clear();
            $path = $directory.'/'.Str::uuid().'.jpg';
            abort_unless(Storage::disk('local')->put($path, $contents), 500, 'No fue posible almacenar la fotografía.');

            return $this->metadata($path, 'image/jpeg');
        }

        $extension = strtolower($photo->guessExtension() ?: 'jpg');
        $path = $photo->storeAs($directory, Str::uuid().'.'.$extension, 'local');
        abort_if($path === false, 500, 'No fue posible almacenar la fotografía.');

        return $this->metadata($path, $mime ?: 'application/octet-stream');
    }

    /** @return array{path:string,mime_type:string,size:int,sha256:string} */
    private function metadata(string $path, string $mime): array
    {
        $absolute = Storage::disk('local')->path($path);

        return ['path' => $path, 'mime_type' => $mime, 'size' => filesize($absolute), 'sha256' => hash_file('sha256', $absolute)];
    }
}
