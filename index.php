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

// RADIX BOT — inyectar widget antes del </body>
$radixBotRoot = $_SERVER['DOCUMENT_ROOT'] . '/radix_bot';
$appJson    = @file_get_contents($radixBotRoot . '/config/app.json');
$contentJson = @file_get_contents($radixBotRoot . '/config/content.json');

if ($appJson !== false && $contentJson !== false) {
    $widgetHtml =
        // Ambos roots requeridos por el JS — fixed-root oculto para no mostrar panel fijo
        '<div id="radix-bot-floating-root"></div>' .
        '<div id="radix-bot-fixed-root" style="display:none!important"></div>' .
        // Mover bot a la izquierda para no tapar el WhatsApp + cerrar panel al cargar
        '<style>' .
            // Mover bot a la izquierda
            '.rb-floating-wrap{right:auto!important;left:18px!important}' .
            '@media(max-width:600px){.rb-floating-wrap{right:auto!important;left:12px!important}}' .
            // Forzar color de texto oscuro — el sitio RADIX hereda color:white que hace el texto invisible
            '#radix-bot-floating-root,#radix-bot-fixed-root{color:#16203a!important}' .
            '#radix-bot-floating-root *,#radix-bot-fixed-root *{color:inherit}' .
            '.rb-floating-button,.rb-floating-button *{color:#fff!important}' .
            // Fix estructura flex: el contenedor interno del panel no tiene display:flex → mensajes no se limitan y tapan los botones
            '.rb-panel-header{flex-shrink:0!important}' .
            '.rb-panel>*:not(.rb-panel-header){display:flex!important;flex-direction:column!important;flex:1!important;overflow:hidden!important;min-height:0!important}' .
            '.rb-lang-picker,.rb-input-wrap{flex-shrink:0!important}' .
            '.rb-messages{flex:1!important;overflow-y:auto!important;min-height:40px!important;max-height:none!important}' .
        '</style>' .
        // Limpiar sessionStorage completo del bot (historial viejo de pruebas)
        '<script>' .
            'sessionStorage.removeItem("radixBotPanelOpen");' .
            'sessionStorage.removeItem("radixBotFixedOpen");' .
            'sessionStorage.removeItem("radixBotState");' .
            'sessionStorage.removeItem("radixBotBubbleClosed");' .
        '</script>' .
        '<script>' .
        'window.RADIX_BOT_CONFIG={' .
        'app:'     . $appJson . ',' .
        'content:' . $contentJson . ',' .
        'apiBase:"/radix_bot"' .
        '};' .
        '</script>' .
        '<link rel="stylesheet" href="/radix_bot/assets/css/radix-bot.css">' .
        '<script src="/radix_bot/assets/js/radix-bot.js" defer></script>';
    $landingHtml = str_ireplace('</body>', $widgetHtml . '</body>', $landingHtml);
}

header('Content-Type: text/html; charset=UTF-8');
echo $landingHtml;
