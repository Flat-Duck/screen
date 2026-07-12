<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f8f9fa; color: #1a1a1a; }
        .card { text-align: center; padding: 2rem; max-width: 24rem; }
        h1 { font-size: 1.25rem; }
    </style>
</head>
<body>
    <div class="card">
        @if ($success)
            <h1>Email address updated</h1>
            <p>You can close this page and return to the app.</p>
        @else
            <h1>This link is invalid or has expired</h1>
            <p>Request the email change again from the app to get a new link.</p>
        @endif
    </div>
</body>
</html>
