<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\File;
use Spatie\Browsershot\Browsershot;

class BrowsershotPdfRenderer
{
    /**
     * @param  array{format?: string, margins?: array{0:int,1:int,2:int,3:int}, landscape?: bool}  $options
     */
    public function render(string $html, array $options = []): string
    {
        $margins = $options['margins'] ?? [10, 10, 10, 10];

        $renderer = Browsershot::html($html)
            ->format($options['format'] ?? 'Letter')
            ->showBackground()
            ->margins($margins[0], $margins[1], $margins[2], $margins[3])
            ->timeout((int) env('BROWSERSHOT_TIMEOUT', 60))
            ->setNodeModulePath(base_path('node_modules'));

        if (($options['landscape'] ?? false) === true) {
            $renderer->landscape();
        }

        if ($chromePath = $this->chromePath()) {
            $renderer->setChromePath($chromePath);
        }

        if ($nodeBinary = env('BROWSERSHOT_NODE_BINARY')) {
            $renderer->setNodeBinary($nodeBinary);
        }

        if ($npmBinary = env('BROWSERSHOT_NPM_BINARY')) {
            $renderer->setNpmBinary($npmBinary);
        }

        $renderer->setNodeEnv([
            'PUPPETEER_CACHE_DIR' => storage_path('app/puppeteer'),
        ]);

        if (filter_var(env('BROWSERSHOT_NO_SANDBOX', true), FILTER_VALIDATE_BOOL)) {
            $renderer->noSandbox();
        }

        return $renderer->pdf();
    }

    private function chromePath(): ?string
    {
        $configured = env('BROWSERSHOT_CHROME_PATH');
        if ($configured) {
            return $configured;
        }

        $matches = File::glob(storage_path('app/puppeteer/chrome/*/chrome-linux64/chrome'));

        return $matches[0] ?? null;
    }
}
