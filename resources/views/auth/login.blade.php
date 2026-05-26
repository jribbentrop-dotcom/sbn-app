<!DOCTYPE html>
<html lang="en" data-theme="modern">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In -- SBN Teaching Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8f9fb; --white: #ffffff; --border: #e2e8f0;
            --text: #2c3e50; --dim: #5a5a5a; --muted: #8896a4;
            --accent: #f39c12; --accent-dim: #e67e22;
            --accent-bg: rgba(243, 156, 18, 0.08);
            --gradient: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%);
            --error: #ef4444; --error-bg: rgba(239, 68, 68, 0.06);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: var(--bg); color: var(--text);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%; max-width: 400px; padding: 20px;
        }
        .inner {
            background: var(--white); border: 1px solid var(--border);
            border-radius: 16px; padding: 40px 36px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
        }
        .logo {
            display: flex; align-items: center; gap: 12px; margin-bottom: 32px;
        }
        .logo svg { width: 36px; height: 36px; }
        .logo span { font-size: 22px; font-weight: 700; letter-spacing: -0.02em; }
        .accent { color: var(--accent); }
        h2 { font-size: 18px; font-weight: 600; margin-bottom: 4px; }
        .sub { color: var(--dim); font-size: 13px; margin-bottom: 28px; }
        .group { margin-bottom: 18px; }
        label {
            display: block; font-size: 12px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.05em;
            color: var(--muted); margin-bottom: 6px;
        }
        input[type="email"], input[type="password"] {
            width: 100%; padding: 11px 14px; background: var(--bg);
            border: 1px solid var(--border); border-radius: 8px;
            color: var(--text); font-family: inherit; font-size: 14px;
            outline: none; transition: border-color 0.15s, box-shadow 0.15s;
        }
        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-bg);
        }
        .remember {
            display: flex; align-items: center; gap: 8px; margin-bottom: 24px;
        }
        .remember label { text-transform: none; font-weight: 500; margin: 0; font-size: 13px; color: var(--dim); }
        .btn {
            width: 100%; padding: 12px; background: var(--gradient); color: #fff;
            border: none; border-radius: 8px; font-family: inherit;
            font-size: 14px; font-weight: 700; cursor: pointer;
            transition: background 0.15s, transform 0.1s;
        }
        .btn:hover { background: linear-gradient(135deg, #e67e22 0%, #c0392b 100%); transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }
        .err {
            background: var(--error-bg); border: 1px solid rgba(239, 68, 68, 0.15);
            color: var(--error); padding: 10px 14px; border-radius: 8px;
            font-size: 13px; margin-bottom: 18px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="inner">
            <div class="logo">
                <svg viewBox="0 0 32 32" fill="none">
                    <defs>
                        <linearGradient id="lg" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stop-color="#f39c12"/>
                            <stop offset="100%" stop-color="#e74c3c"/>
                        </linearGradient>
                    </defs>
                    <rect width="32" height="32" rx="8" fill="url(#lg)"/>
                    <path d="M8 22V10l8 6-8 6z" fill="#fff" opacity="0.9"/>
                    <path d="M16 22V10l8 6-8 6z" fill="#fff" opacity="0.6"/>
                </svg>
                <span>SBN <span class="accent">Hub</span></span>
            </div>
            <h2>Welcome back</h2>
            <p class="sub">Sign in to manage your teaching content.</p>

            @if($errors->any())
                <div class="err">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
                </div>
                <div class="group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="remember">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Keep me signed in</label>
                </div>
                <button type="submit" class="btn">Sign In</button>
            </form>
        </div>
    </div>
</body>
</html>
