<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f8f9fa; color: #1a1a1a; }
        .card { text-align: center; padding: 2rem; max-width: 24rem; }
        .btn { display: inline-block; margin-top: 1.5rem; padding: 0.75rem 1.5rem; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 0.5rem; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Open this {{ $destination }} in Akukas</h1>
        <p>Install Akukas to continue. Access to this content is checked by the app.</p>
        <a class="btn" href="{{ $playStoreUrl }}">Get the app</a>
    </main>
</body>
</html>
