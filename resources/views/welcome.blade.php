<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>{{ config('app.name', 'Douwyn Starter Kit') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <x-theme-bootstrap />
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <style>
                body { margin: 0; font-family: ui-sans-serif, system-ui, sans-serif; background: #fff; color: #000; }
                .dark body { background: #000; color: #fff; }
                .auth-shell { min-height: 100vh; display: grid; grid-template-columns: 1fr 1fr; }
                .auth-aside { display: flex; padding: 3rem; background: #000; color: #fff; }
                .auth-aside-inner { display: flex; flex-direction: column; justify-content: space-between; gap: 2rem; }
                .brand { display: flex; align-items: center; gap: 1rem; color: inherit; text-decoration: none; }
                .brand-mark { display: grid; place-items: center; background: #fff; padding: .5rem; border-radius: .75rem; }
                .auth-aside-title { font-size: clamp(2rem, 5vw, 4rem); line-height: 1.1; }
                .auth-main { display: grid; align-items: center; padding: 3rem; }
                .auth-card { border: 1px solid currentColor; border-radius: 1rem; padding: 2rem; }
                .welcome-resource-list { padding-left: 1.25rem; line-height: 2; }
                .text-link { color: inherit; }
                .button { display: inline-block; padding: .75rem 1rem; border: 1px solid currentColor; border-radius: .75rem; color: inherit; text-decoration: none; }
                .button-primary { background: #000; color: #fff; }
                .dark .button-primary { background: #fff; color: #000; }
                .welcome-nav { display: flex; flex-wrap: wrap; gap: .75rem; margin-bottom: 1.5rem; }
                @media (max-width: 64rem) { .auth-shell { grid-template-columns: 1fr; } .auth-aside, .auth-main { padding: 1.5rem; } }
            </style>
        @endif
    </head>
    <body class="guest-body">
        <div class="auth-shell">
            <aside class="auth-aside">
                <div class="auth-aside-inner">
                    <a class="brand" href="{{ url('/') }}">
                        <span class="brand-mark" aria-hidden="true">
                            <img src="{{ asset('logo.png') }}" alt="" width="32" height="32">
                        </span>
                        <span class="brand-name">{{ config('app.name', 'Douwyn Starter Kit') }}</span>
                    </a>

                    <div>
                        <h1 class="auth-aside-title">{{ __('welcome.heading') }}</h1>
                        <p class="auth-aside-text">{{ __('welcome.description_line_one') }}</p>
                    </div>

                    <p class="auth-aside-footnote">© {{ now()->year }} {{ config('app.name', 'Douwyn Starter Kit') }}</p>
                </div>
            </aside>

            <main class="auth-main" id="main-content">
                <div class="auth-main-inner">
                    @if (Route::has('login'))
                        <nav class="welcome-nav">
                            @auth
                                <a class="button button-secondary" href="{{ url('/dashboard') }}">{{ __('welcome.dashboard') }}</a>
                            @else
                                <a class="button button-secondary" href="{{ route('login') }}">{{ __('welcome.login') }}</a>
                                @if (Route::has('register'))
                                    <a class="button button-secondary" href="{{ route('register') }}">{{ __('welcome.register') }}</a>
                                @endif
                            @endauth
                        </nav>
                    @endif
                    <section class="auth-card" aria-labelledby="welcome-title">
                        <header class="auth-card-header">
                            <h2 class="auth-title" id="welcome-title">{{ __('welcome.heading') }}</h2>
                            <p class="auth-description">{{ __('welcome.description_line_two') }}</p>
                        </header>

                        <ul class="welcome-resource-list">
                            <li>
                                {{ __('welcome.read_the') }}
                                <a class="text-link" href="https://laravel.com/docs" target="_blank" rel="noopener noreferrer">{{ __('welcome.documentation') }}</a>
                            </li>
                            <li>
                                {{ __('welcome.watch_tutorials_at') }}
                                <a class="text-link" href="https://laracasts.com" target="_blank" rel="noopener noreferrer">Laracasts</a>
                            </li>
                        </ul>

                        <a class="button button-primary button-block" href="https://cloud.laravel.com" target="_blank" rel="noopener noreferrer">
                            {{ __('welcome.deploy_now') }}
                        </a>
                    </section>
                </div>
            </main>
        </div>
    </body>
</html>
