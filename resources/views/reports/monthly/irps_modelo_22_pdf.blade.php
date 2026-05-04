<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('IRPS Model 22') }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 7px; color: #111; margin: 0; }
        .hdr { text-align: center; margin-bottom: 8px; }
        .hdr h1 { font-size: 11px; margin: 0 0 4px 0; }
        .hdr .sub { font-size: 8px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 3px 4px; vertical-align: middle; }
        th { background: #eee; font-weight: 700; text-align: left; }
        .num { text-align: right; white-space: nowrap; }
        .cen { text-align: center; }
        .foot td { font-weight: 700; background: #f0f0f0; }
        .note { font-size: 6.5px; color: #555; margin-top: 6px; }
    </style>
</head>
<body>
@php
    $settings = \App\Models\Utility::settings();
    $companyName = $settings['company_name'] ?? '';
    $nuit = $settings['vat_number'] ?? '';
@endphp
<div class="hdr">
    <h1>{{ __('MODELO 22 — IRPS / Payroll (monthly summary)') }}</h1>
    @if (count($rows) === 1)
        <div class="sub" style="font-style: italic;">{{ __('Individual — one employee / payslip') }}</div>
    @else
        <div class="sub">{{ __('Full report — all employees') }}</div>
    @endif
    <div class="sub">{{ $companyName }} @if($nuit) · NUIT: {{ $nuit }} @endif</div>
    <div class="sub">{{ __('Month') }}: {{ $filter['month'] }}</div>
</div>

@if (count($rows) === 0)
    <p>{{ __('No payslip records for this month.') }}</p>
@else
    <table>
        <thead>
            <tr>
                <th>{{ __('Emp.') }}</th>
                <th>{{ __('Name') }}</th>
                <th>{{ __('NUIT') }}</th>
                <th class="cen">{{ __('Dep.') }}</th>
                <th class="num">{{ __('Basic') }}</th>
                <th class="num">{{ __('Allow.') }}</th>
                <th class="num">{{ __('Comm.') }}</th>
                <th class="num">{{ __('OT') }}</th>
                <th class="num">{{ __('Oth.') }}</th>
                <th class="num">{{ __('Σ earn') }}</th>
                <th class="num">{{ __('Loan') }}</th>
                <th class="num">{{ __('Sat.') }}</th>
                <th class="num">{{ __('INSS e') }}</th>
                <th class="num">{{ __('IRPS') }}</th>
                <th class="num">{{ __('Gross*') }}</th>
                <th class="num">{{ __('Net') }}</th>
                <th class="num">{{ __('INSS er') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ $r['employee_id'] }}</td>
                    <td>{{ $r['name'] }}</td>
                    <td>{{ $r['tax_payer_id'] }}</td>
                    <td class="cen">{{ $r['dependents'] }}</td>
                    <td class="num">{{ number_format($r['basic'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['allowance'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['commission'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['overtime'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['other_payment'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['total_earnings_components'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['loan'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['saturation'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['inss_employee'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['irps_a_reter'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['gross_irps_base'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['net'], 2, ',', '.') }}</td>
                    <td class="num">{{ number_format($r['inss_employer'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="foot">
            <tr>
                <td colspan="4">{{ __('TOTAL') }}</td>
                <td class="num">{{ number_format($totals['basic'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['allowance'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['commission'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['overtime'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['other_payment'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['total_earnings_components'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['loan'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['saturation'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['inss_employee'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['irps_a_reter'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['gross_irps_base'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['net'], 2, ',', '.') }}</td>
                <td class="num">{{ number_format($totals['inss_employer'], 2, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
    <p class="note">* {{ __('Gross (IRPS base) = Net + INSS (employee) + IRPS (on payslip)') }}. {{ __('IRPS column: calculated withholding.') }}</p>
@endif
</body>
</html>
