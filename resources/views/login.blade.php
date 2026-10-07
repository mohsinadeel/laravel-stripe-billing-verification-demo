<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in to the Stripe demo</title></head>
<body>
<main>
    <h1>Sign in to the Stripe demo</h1>
    <p>Use your main website account. This demo keeps its own login session.</p>
    @if ($errors->any())
        <p role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <p><label>Email <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required></label></p>
        <p><label>Password <input type="password" name="password" autocomplete="current-password" required></label></p>
        <button type="submit">Sign in</button>
    </form>
</main>
</body>
</html>