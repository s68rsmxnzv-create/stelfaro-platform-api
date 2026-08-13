<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Models\Tenant;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\TenantFiscalLinkResolver;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PublicQuotationController extends Controller
{
    public function __invoke(string $token, BrowsershotPdfRenderer $pdfRenderer, TenantFiscalLinkResolver $fiscalLinks): Response
    {
        $quotation = Quotation::query()->where('public_token', $token)->with(['lines', 'tenant'])->firstOrFail();

        $logoDataUri = $this->companyLogoDataUri($quotation->tenant, $fiscalLinks);
        $companyProfile = $logoDataUri ? $this->companyProfile($quotation->core_sucursal_id) : null;

        $html = view('quotations.pdf', compact('quotation', 'logoDataUri', 'companyProfile'))->render();
        $number = 'C-'.str_pad((string) $quotation->quotation_number, 6, '0', STR_PAD_LEFT);

        return response($pdfRenderer->render($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Cotizacion-'.$number.'.pdf"',
        ]);
    }

    private function companyLogoDataUri(Tenant $tenant, TenantFiscalLinkResolver $fiscalLinks): ?string
    {
        try {
            $empresaId = $fiscalLinks->coreEmpresaId($tenant);
        } catch (RuntimeException) {
            return null;
        }

        return Cache::remember("quotation-pdf:logo-data-uri:{$empresaId}", 3600, function () use ($empresaId): ?string {
            $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
            if ($baseUrl === '') {
                return null;
            }

            try {
                $response = Http::timeout(10)->get("{$baseUrl}/billing/companies/{$empresaId}/logo");
            } catch (Throwable) {
                return null;
            }

            if ($response->failed()) {
                return null;
            }

            $mime = $response->header('Content-Type') ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode($response->body());
        });
    }

    /**
     * @return array{empresa: array<string, mixed>, sucursal: array<string, mixed>}|null
     */
    private function companyProfile(?int $sucursalId): ?array
    {
        if (! $sucursalId) {
            return null;
        }

        return Cache::remember("quotation-pdf:company-profile:{$sucursalId}", 3600, function () use ($sucursalId): ?array {
            $baseUrl = rtrim((string) config('services.dte_core.base_url'), '/');
            $internalToken = config('services.dte_core.internal_token');
            if ($baseUrl === '' || ! is_string($internalToken) || $internalToken === '') {
                return null;
            }

            try {
                $response = Http::acceptJson()
                    ->withToken($internalToken)
                    ->timeout(10)
                    ->get("{$baseUrl}/internal/billing/sucursales/{$sucursalId}/profile");
            } catch (Throwable) {
                return null;
            }

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        });
    }
}
