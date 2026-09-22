<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} invite</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f8f9fa; color: #1a1a1a; }
        .card { text-align: center; padding: 2rem; max-width: 24rem; }
        h1 { font-size: 1.25rem; }
        .code { font-family: ui-monospace, monospace; background: #eee; padding: 0.15rem 0.5rem; border-radius: 0.25rem; }
        .btn { display: inline-block; margin-top: 1.5rem; padding: 0.75rem 1.5rem; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 0.5rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>You've been invited to {{ config('app.name') }}</h1>
        <p>Open this link on your phone with the app installed to use invite code
            <span class="code">{{ $code }}</span> automatically — or install the app first:</p>
        <a class="btn" href="{{ $playStoreUrl }}">Get the app</a>
    </div>
</body>
</html>
