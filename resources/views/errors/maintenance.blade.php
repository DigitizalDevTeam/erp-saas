<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('errors.maintenance') }} - {{ config('app.name', 'ERP') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #333;
        }
        .maintenance-box {
            text-align: center;
            max-width: 480px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            padding: 48px 32px;
        }
        .maintenance-box img {
            max-width: 100%;
            height: auto;
            margin-bottom: 24px;
            border-radius: 8px;
        }
        .maintenance-box h1 {
            margin: 0 0 12px;
            font-size: 1.75rem;
            font-weight: 600;
            color: #1a1a1a;
        }
        .maintenance-box p {
            margin: 0;
            font-size: 1rem;
            line-height: 1.6;
            color: #555;
        }
        .maintenance-box .icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="maintenance-box">
        @if(file_exists(public_path('images/maintenance.png')))
            <img src="{{ asset('images/maintenance.png') }}" alt="{{ __('errors.maintenance') }}">
        @else
            <div class="icon" aria-hidden="true">&#128736;</div>
        @endif
        <h1>{{ __('errors.maintenance') }}</h1>
        <p>{{ __('errors.maintenance_message') }}</p>
    </div>
</body>
</html>
