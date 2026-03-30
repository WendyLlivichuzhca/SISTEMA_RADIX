<?php
$styleVersion = @filemtime(__DIR__ . '/style.css') ?: time();
$appVersion = @filemtime(__DIR__ . '/app.js') ?: time();
$heroVersion = @filemtime(__DIR__ . '/hero-img.png') ?: time();

$landingHtml = @file_get_contents(__DIR__ . '/index.html');

if ($landingHtml === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('No se pudo cargar la landing de RADIX.');
}

$landingHtml = str_replace(
    '<link rel="stylesheet" href="style.css">',
    '<link rel="stylesheet" href="style.css?v=' . $styleVersion . '">',
    $landingHtml
);

$landingHtml = str_replace(
    '<script src="app.js" defer></script>',
    '<script src="app.js?v=' . $appVersion . '" defer></script>',
    $landingHtml
);

$landingHtml = str_replace(
    '<img id="hero-img" src="hero-img.png" alt="NFT o Cripto">',
    '<img id="hero-img" src="hero-img.png?v=' . $heroVersion . '" alt="NFT o Cripto">',
    $landingHtml
);

header('Content-Type: text/html; charset=UTF-8');
echo $landingHtml;
