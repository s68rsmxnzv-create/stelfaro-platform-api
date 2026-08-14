<?php

namespace App\Services\Legal;

use App\Models\LegalDocument;
use Illuminate\Support\Collection;
use LogicException;

class LegalDocumentRegistry
{
    /**
     * @return Collection<int, LegalDocument>
     */
    public function current(): Collection
    {
        return collect(config('legal.documents', []))
            ->map(function (array $definition, string $type): LegalDocument {
                $source = file_get_contents(base_path($definition['source_path']));

                if ($source === false) {
                    throw new LogicException("No se encontró el documento legal [{$type}].");
                }

                $contentHash = hash('sha256', $source);

                if (! hash_equals($definition['content_hash'], $contentHash)) {
                    throw new LogicException("El contenido legal [{$type}] cambió sin registrar una versión nueva.");
                }

                $document = LegalDocument::query()->firstOrCreate(
                    ['type' => $type, 'version' => $definition['version']],
                    [
                        'title' => $definition['title'],
                        'effective_at' => $definition['effective_at'],
                        'public_path' => $definition['public_path'],
                        'content_hash' => $contentHash,
                        'content_snapshot' => $source,
                    ],
                );

                if (
                    ! hash_equals($document->content_hash, $contentHash)
                    || ! hash_equals(hash('sha256', $document->content_snapshot), $contentHash)
                    || $document->title !== $definition['title']
                    || $document->effective_at->toDateString() !== $definition['effective_at']
                    || $document->public_path !== $definition['public_path']
                ) {
                    throw new LogicException("La versión legal [{$type}:{$definition['version']}] ya existe con otro contenido.");
                }

                return $document;
            })
            ->values();
    }
}
