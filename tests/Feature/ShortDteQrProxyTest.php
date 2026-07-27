<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShortDteQrProxyTest extends TestCase
{
    public function test_it_proxies_a_valid_short_qr_redirect(): void
    {
        config([
            'platform.hosts.facturacion' => 'facturacion.stelfaro.com',
            'services.dte_core.base_url' => 'http://dte-core.test/api/v1',
        ]);
        Http::fake([
            'http://dte-core.test/api/v1/q/m/731/ABCDEFGHIJKL' => Http::response('', 302, [
                'Location' => 'https://admin.factura.gob.sv/consultaPublica?ambiente=01',
            ]),
        ]);

        $this->get('https://facturacion.stelfaro.com/q/m/731/ABCDEFGHIJKL')
            ->assertRedirect('https://admin.factura.gob.sv/consultaPublica?ambiente=01');
    }

    public function test_it_rejects_an_untrusted_redirect_host(): void
    {
        config([
            'platform.hosts.facturacion' => 'facturacion.stelfaro.com',
            'services.dte_core.base_url' => 'http://dte-core.test/api/v1',
        ]);
        Http::fake([
            '*' => Http::response('', 302, ['Location' => 'https://evil.example/phishing']),
        ]);

        $this->get('https://facturacion.stelfaro.com/q/d/731/ABCDEFGHIJKL')
            ->assertNotFound();
    }
}
