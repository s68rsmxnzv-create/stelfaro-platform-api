#!/usr/bin/env php
<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

if ($argc !== 4) {
    fail('Uso: php scripts/publish-agent-release.php <windows|android> <archivo> <version>');
}

[$script, $platform, $source, $version] = $argv;
unset($script);

$definitions = [
    'windows' => [
        'extension' => 'zip',
        'versioned' => 'stelfaro-print-agent-%s-windows.zip',
        'latest' => 'stelfaro-print-agent-windows-latest.zip',
    ],
    'android' => [
        'extension' => 'apk',
        'versioned' => 'stelfaro-print-agent-%s-android.apk',
        'latest' => 'stelfaro-print-agent-android-latest.apk',
    ],
];

if (! isset($definitions[$platform])) {
    fail('Plataforma inválida. Usa windows o android.');
}
if (! is_file($source) || ! is_readable($source)) {
    fail('No se puede leer el artefacto: '.$source);
}
if (! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?$/', $version)) {
    fail('La versión debe tener formato semántico, por ejemplo 1.2.0 o 1.2.0-beta.1.');
}

$definition = $definitions[$platform];
if (strtolower((string) pathinfo($source, PATHINFO_EXTENSION)) !== $definition['extension']) {
    fail(sprintf('El artefacto de %s debe tener extensión .%s.', $platform, $definition['extension']));
}

$downloads = dirname(__DIR__).'/public/downloads';
if (! is_dir($downloads) && ! mkdir($downloads, 0775, true) && ! is_dir($downloads)) {
    fail('No se pudo crear el directorio de descargas.');
}

$versionedName = sprintf($definition['versioned'], $version);
$versionedPath = $downloads.'/'.$versionedName;
$latestPath = $downloads.'/'.$definition['latest'];
$temporaryVersioned = $versionedPath.'.tmp';
$temporaryLatest = $latestPath.'.tmp';

if (! copy($source, $temporaryVersioned) || ! rename($temporaryVersioned, $versionedPath)) {
    @unlink($temporaryVersioned);
    fail('No se pudo publicar el artefacto versionado.');
}
if (! copy($versionedPath, $temporaryLatest) || ! rename($temporaryLatest, $latestPath)) {
    @unlink($temporaryLatest);
    fail('No se pudo actualizar el alias latest.');
}

$manifestPath = $downloads.'/agent-releases.json';
$manifest = [];
if (is_file($manifestPath)) {
    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    $manifest = is_array($decoded) ? $decoded : [];
}

$manifest[$platform] = [
    'available' => true,
    'version' => $version,
    'file' => $definition['latest'],
    'versioned_file' => $versionedName,
    'size' => filesize($versionedPath),
    'sha256' => hash_file('sha256', $versionedPath),
    'published_at' => gmdate(DATE_ATOM),
];

$temporaryManifest = $manifestPath.'.tmp';
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
if (file_put_contents($temporaryManifest, $json, LOCK_EX) === false || ! rename($temporaryManifest, $manifestPath)) {
    @unlink($temporaryManifest);
    fail('El artefacto se publicó, pero no se pudo actualizar el manifiesto.');
}

printf(
    "%s %s publicado como %s\nSHA-256: %s\n",
    ucfirst($platform),
    $version,
    $definition['latest'],
    $manifest[$platform]['sha256'],
);

$artisan = dirname(__DIR__).'/artisan';
$notifyCommand = sprintf(
    'php %s agent-notifications:generate %s %s 2>&1',
    escapeshellarg($artisan),
    escapeshellarg($platform),
    escapeshellarg($version),
);
exec($notifyCommand, $notifyOutput, $notifyStatus);
if ($notifyStatus !== 0) {
    fwrite(STDERR, "Aviso: el artefacto se publicó, pero no se pudieron generar las notificaciones internas:\n".implode("\n", $notifyOutput).PHP_EOL);
} else {
    echo implode("\n", $notifyOutput).PHP_EOL;
}
