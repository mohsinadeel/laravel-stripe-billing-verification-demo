<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · Stripe billing demo</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100svh; background: linear-gradient(#0a0a0a, #171717); color: #fafafa; font: 14px/1.5 system-ui, sans-serif; }
        main { min-height: 100svh; display: flex; align-items: center; justify-content: center; padding: 32px 24px; }
        .auth { width: 100%; max-width: 384px; }
        .brand { display: block; margin-bottom: 32px; text-align: center; color: #d4d4d4; font-weight: 600; text-decoration: none; }
        header { text-align: center; margin-bottom: 28px; }
        h1 { margin: 0 0 8px; font-size: 24px; line-height: 1.3; font-weight: 600; letter-spacing: -.5px; }
        p { margin: 0; color: #a3a3a3; }
        form { display: grid; gap: 24px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; }
        input { width: 100%; height: 44px; border: 1px solid #404040; border-radius: 8px; padding: 10px 12px; background: #262626; color: #fafafa; font: inherit; }
        input::placeholder { color: #737373; }
        input:focus-visible, button:focus-visible, a:focus-visible { outline: 2px solid #a3a3a3; outline-offset: 3px; }
        input[aria-invalid="true"] { border-color: #f87171; }
        button { min-height: 44px; border: 0; border-radius: 8px; padding: 10px 16px; background: #fafafa; color: #171717; font: inherit; font-weight: 600; cursor: pointer; }
        button:hover { background: #e5e5e5; }
        .error { margin-bottom: 24px; border: 1px solid #7f1d1d; border-radius: 8px; padding: 12px; color: #fca5a5; background: #450a0a; }
        footer { margin-top: 24px; text-align: center; color: #a3a3a3; }
        .note { margin-top: 12px; font-size: 12px; }
    </style>
</head>
<body>
<main>
    <div class="auth">
        <a class="brand" href="{{ route('login') }}">Engineering Demos</a>
        <header>
            <h1>Log in to the Stripe demo</h1>
            <p>Enter your main website email and password.</p>
        </header>
        @if ($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <div>
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" placeholder="email@example.com" required autofocus @error('email') aria-invalid="true" @enderror>
            </div>
            <div>
                <label for="password">Password</label>
                <input id="password" type="password" name="password" autocomplete="current-password" placeholder="Password" required @error('password') aria-invalid="true" @enderror>
            </div>
            <button type="submit">Log in</button>
        </form>
        <footer>
            <p>This demo keeps its own login session.</p>
            <p class="note">Use an account created on the main website.</p>
        </footer>
    </div>
</main>
</body>
</html>