<!doctype html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $bootstrap['csrf'] }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <script>try{if(window.localStorage.getItem('request-tracer:theme')==='light'){document.documentElement.className='light'}}catch(e){}</script>
    <link rel="icon" href="{{ $assetBase }}/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="{{ $assetBase }}/app.css?v={{ $version }}">
</head>
<body>
    <div id="app"></div>
    <noscript>{{ $title }} needs JavaScript.</noscript>
    <script>window.__REQUEST_TRACER__ = @json($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);</script>
    <script type="module" src="{{ $assetBase }}/app.js?v={{ $version }}"></script>
</body>
</html>
