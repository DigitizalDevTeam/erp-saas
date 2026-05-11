<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('IRPC Payment Guide') }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111; margin: 0; }
        .page { padding: 14px; }
        .header { text-align: center; margin-bottom: 14px; }
        .header h1 { margin: 0; font-size: 18px; }
        .header p { margin: 2px 0; color: #444; }
        .section-title { font-size: 12px; font-weight: 700; margin: 12px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 6px 8px; vertical-align: top; }
        th { background: #efefef; text-align: left; }
        .num { text-align: right; white-space: nowrap; }
        .summary td { font-weight: 700; background: #f3f3f3; }
        .grid { width: 100%; margin-bottom: 8px; }
        .grid td { width: 50%; }
        .note { margin-top: 10px; font-size: 10px; color: #555; }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <h1>{{ __('GUIA DE PAGAMENTO - IRPC') }}</h1>
        <p>{{ __('Corporate income tax payment guide') }}</p>
        <p>{{ __('Fiscal year') }}: {{ $reportData['fiscal_year'] }} | {{ __('Period') }}: {{ $reportData['period_label'] }}</p>
    </div>

    <div class="section-title">{{ __('Company details') }}</div>
    <table class="grid">
        <tr>
            <td><strong>{{ __('Company') }}:</strong> {{ $reportData['company_name'] ?: '-' }}</td>
            <td><strong>{{ __('NUIT') }}:</strong> {{ $reportData['company_nuit'] ?: '-' }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('Address') }}:</strong> {{ $reportData['company_address'] ?: '-' }}</td>
            <td><strong>{{ __('City') }}:</strong> {{ $reportData['company_city'] ?: '-' }}</td>
        </tr>
        <tr>
            <td><strong>{{ __('Phone') }}:</strong> {{ $reportData['company_phone'] ?: '-' }}</td>
            <td><strong>{{ __('Email') }}:</strong> {{ $reportData['company_email'] ?: '-' }}</td>
        </tr>
    </table>

    <div class="section-title">{{ __('Calculation basis') }}</div>
    <table>
        <thead>
            <tr>
                <th style="width: 70px;">{{ __('Line') }}</th>
                <th>{{ __('Description') }}</th>
                <th class="num" style="width: 160px;">{{ __('Amount (MT)') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>{{ __('Revenue from accounting statements') }}</td>
                <td class="num">{{ number_format($reportData['total_revenue'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>2</td>
                <td>{{ __('Operating costs') }}</td>
                <td class="num">{{ number_format($reportData['operating_costs'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>3</td>
                <td>{{ __('Financial costs') }}</td>
                <td class="num">{{ number_format($reportData['financial_costs'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>4</td>
                <td>{{ __('Extraordinary costs') }}</td>
                <td class="num">{{ number_format($reportData['extraordinary_costs'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>5</td>
                <td>{{ __('Accounting profit before tax') }}</td>
                <td class="num">{{ number_format($reportData['accounting_profit_before_tax'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>6</td>
                <td>{{ __('Taxable income') }}</td>
                <td class="num">{{ number_format($reportData['taxable_income'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>7</td>
                <td>{{ __('IRPC rate') }}</td>
                <td class="num">{{ number_format($reportData['irpc_rate_percent'], 2, ',', '.') }}%</td>
            </tr>
            <tr>
                <td>8</td>
                <td>{{ __('IRPC payable') }}</td>
                <td class="num">{{ number_format($reportData['irpc_payable'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>9</td>
                <td>{{ __('Prior payments / adjustments') }}</td>
                <td class="num">{{ number_format($reportData['prior_payments'], 2, ',', '.') }}</td>
            </tr>
        </tbody>
        <tfoot class="summary">
            <tr>
                <td colspan="2">{{ __('Net amount payable') }}</td>
                <td class="num">{{ number_format($reportData['net_payable'], 2, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="section-title">{{ __('Accounting integration reference') }}</div>
    <table>
        <tr>
            <th>{{ __('DRE years') }}</th>
            <td>{{ $reportData['source_report']['dre']['year1'] }} / {{ $reportData['source_report']['dre']['year2'] }}</td>
        </tr>
        <tr>
            <th>{{ __('Trial balance period') }}</th>
            <td>{{ $reportData['source_report']['trial_balance']['start_date'] }} - {{ $reportData['source_report']['trial_balance']['end_date'] }}</td>
        </tr>
    </table>

    <p class="note">
        {{ __('Generated from annual accounting statements for the selected fiscal year. The first version uses accounting profit before tax as taxable income and applies the configured IRPC rate directly.') }}
    </p>
</div>
</body>
</html>
