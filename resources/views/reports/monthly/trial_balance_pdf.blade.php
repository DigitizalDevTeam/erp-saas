<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Trial Balance (Monthly Report)') }}</title>
    <style>
        @page { margin: 10mm 8mm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; font-size: 11px; }
        .text-center { text-align: center; }
        .text-end { text-align: right; }
        .fw-bold { font-weight: 700; }
        .muted { color: #4b5563; }
        .mb-10 { margin-bottom: 10px; }
        .mb-6 { margin-bottom: 6px; }
        .h1 { font-size: 16px; }
        .h2 { font-size: 12px; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; vertical-align: top; }
        thead th { font-weight: 700;white-space: nowrap; }
        tbody {font-size: 10px;}
        .topline { border-top: 1px solid #111;font-size: 10px; }
        .code { width: 10%; white-space: nowrap; }
        .account { width: 38%; }
        .num { width: 13%; }

        .row-parent td { border-bottom: 1px dashed #111; padding-top: 10px; }
        .row-sub td { border-bottom: 0; }
        .row-total td { border-top: 1px solid #111; font-weight: 700; padding-top: 8px; }
        .indent-1 { padding-left: 18px; }
        .underline { text-decoration: underline; }
    </style>
</head>
<body>
@php
    $authUser = \Auth::user()->creatorId();
    $user = \App\Models\User::find($authUser);
    $settings = \App\Models\Utility::settings();
    $companyName = $settings['company_name'] ?? ($user?->name ?? '');
    $taxNumber = $settings['vat_number'] ?? '';
    $totalDebit = $grandTotalDebit ?? 0;
    $totalCredit = $grandTotalCredit ?? 0;
    $generatedAt = now()->format('d/m/Y');
@endphp

<table class="mb-10">
    <tr>
        <td style="width: 100%;" class="text-center">
            <div class="fw-bold h1">{{ $companyName }}</div>
            @if (!empty($taxNumber))
                <div class="h2">NUIT:&nbsp;&nbsp;{{ $taxNumber }}</div>
            @endif
        </td>
    </tr>
</table>
<table class="mb-10">
    <tr>
        <td style="width: 20%;" class="">{{ $generatedAt }}</td>
        <td style="width: 60%;" class="text-center"></td>
        <td style="width: 20%;" class="text-end">
            <div class="fw-bold"><span>{{ __('Type') }}:</span> {{ __('Periodical') }}</div>
            <div class="mb-6 fw-bold" style="white-space: nowrap;"><span class="">{{ __('Date') }}:</span> {{ $filter['startDateRange'] }} {{ __('to') }} {{ $filter['endDateRange'] }}</div>
            @php
                $periodOptions = \App\Services\TrialBalanceReportService::accountingPeriodOptions();
                $selectedPeriod = $filter['accountingPeriod'] ?? null;
            @endphp
            @if($selectedPeriod !== null && $selectedPeriod !== '' && isset($periodOptions[$selectedPeriod]))
                <div class="mb-6"><span class="fw-bold">{{ __('Accounting period') }}:</span> {{ $periodOptions[$selectedPeriod] }}</div>
            @endif
        </td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th class="code text-center"></th>
            <th class="account"></th>
            <th class="num text-end">{{ __('Debito') }}</th>
            <th class="num text-end">{{ __('Credito') }}</th>
            <th class="num text-end">{{ __('Saldo Devedor') }}</th>
            <th class="num text-end">{{ __('Saldo Credor') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($totalAccounts as $type => $accounts)
            @foreach ($accounts as $record)
                @php
                    $saldoDevedor = (float) ($record['balanceDebit'] ?? 0);
                    $saldoCredor = (float) ($record['balanceCredit'] ?? 0);

                    $rowClass = match ($record['account'] ?? '') {
                        'parent' => 'row-parent',
                        'parentTotal' => 'row-total',
                        default => 'row-sub',
                    };
                @endphp

                <tr class="{{ $rowClass }}">
                    <td class="{{ ($record['account'] ?? '') === 'parent' ? '' : 'text-center' }} fw-bold">{{ $record['account_code'] }}</td>
                    <td class="{{ ($record['account'] ?? '') === 'subAccount' ? 'indent-1' : '' }}">
                        @if (($record['account'] ?? '') === 'parent')
                            <span class="fw-bold underline">{{ $record['account_name'] }}</span>
                        @elseif (($record['account'] ?? '') === 'parentTotal')
                            <span class="fw-bold">{{ $record['account_name'] }}</span>
                        @else
                            {{ $record['account_name'] }}
                        @endif
                    </td>
                    <td class="text-end {{ ($record['account'] ?? '') === 'parent' ? 'fw-bold' : '' }}">{{ \Auth::user()->priceFormatWithoutCurrency($record['totalDebit'] ?? 0) }}</td>
                    <td class="text-end {{ ($record['account'] ?? '') === 'parent' ? 'fw-bold' : '' }}">{{ \Auth::user()->priceFormatWithoutCurrency($record['totalCredit'] ?? 0) }}</td>
                    <td class="text-end {{ ($record['account'] ?? '') === 'parent' ? 'fw-bold' : '' }}">{{ \Auth::user()->priceFormatWithoutCurrency($saldoDevedor) }}</td>
                    <td class="text-end {{ ($record['account'] ?? '') === 'parent' ? 'fw-bold' : '' }}">{{ \Auth::user()->priceFormatWithoutCurrency($saldoCredor) }}</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
    <tfoot>
        @php
            $grandBalance = $totalDebit - $totalCredit;
            $grandDevedor = $grandBalance > 0 ? $grandBalance : 0;
            $grandCredor = $grandBalance < 0 ? abs($grandBalance) : 0;
        @endphp
        <tr>
            <td colspan="6"></td>
        </tr>
        <tr >
            <td></td>
            <td></td>   
            <td class="text-end fw-bold" colspan="2">{{ __('Debit') }}</td>
            <td class="text-end fw-bold" colspan="2">{{ __('Credit') }}</td>
        </tr>
        <tr class="row-total topline">
            <td class="fw-bold" style="text-decoration: underline;text-decoration-style: double;">{{ __('Summary') }} :</td>
            <td></td>
            <td class="text-end fw-bold" colspan="2">{{ \Auth::user()->priceFormatWithoutCurrency($totalDebit) }}</td>
            <td class="text-end fw-bold" colspan="2">{{ \Auth::user()->priceFormatWithoutCurrency($totalCredit) }}</td>
        </tr>
    </tfoot>
</table>
</body>
</html>

