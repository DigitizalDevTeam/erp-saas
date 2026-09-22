<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('End of Day (EOD) Report') }} — {{ $date }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; margin: 0; padding: 16px; }
        h1 { font-size: 16px; margin: 0 0 8px; }
        .muted { color: #555; }
        .section { margin-bottom: 14px; }
        .section strong.title { display: block; margin-bottom: 4px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f3f3f3; font-size: 10px; }
        .text-end { text-align: right; }
        .line { border-top: 1px solid #999; margin: 8px 0; }
        .hr { color: #666; letter-spacing: 1px; }
    </style>
</head>
<body>
@php
    $fmt = function ($amount) use ($settings) {
        return \App\Models\Utility::priceFormat($settings ?? [], (float) $amount);
    };
@endphp

    <h1>{{ __('End of Day (EOD) Report') }}</h1>
    <div class="section">
        <div class="hr">----------------------------------------</div>
        {{ __('Company') }}: {{ $companyName }}<br>
        {{ __('VAT NR') }}: {{ $vatNumber }}<br>
        {{ __('Address') }}: {{ $companyAddress }}<br>
        {{ __('Total Daily') }}: {{ $fmt($totalSales) }} &nbsp; {{ __('Cash Register') }}: 1<br>
        {{ __('Ticket From') }}: {{ sprintf('%09d', $ticketFrom ?? 0) }} {{ __('To') }}: {{ sprintf('%09d', $ticketTo ?? 0) }}<br>
        {{ __('Receipts Issued') }}: {{ $receiptsCount }}<br>
        {{ __('Date') }}: {{ \Carbon\Carbon::parse($date)->format('m/d/Y') }} &nbsp; {{ __('Time') }}: {{ date('H:i:s') }}<br>
        {{ __('SALESPERSON') }}: {{ $salespersonName }}<br>
        <div class="hr">----------------------------------------</div>
    </div>

    <div class="section">
        <strong class="title">{{ __('SALES BY PAYMENT METHOD') }}</strong>
        <table>
            <thead>
                <tr>
                    <th>{{ __('PAYMENT METHOD') }}</th>
                    <th class="text-end">{{ __('AMOUNT') }}</th>
                    <th class="text-end">{{ __('NO.') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($paymentMethodRows as $r)
                    <tr>
                        <td>{{ $r['name'] }}</td>
                        <td class="text-end">{{ $fmt($r['amount']) }}</td>
                        <td class="text-end">{{ $r['count'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td class="text-end"><strong>{{ $fmt($totalSales) }}</strong></td>
                    <td class="text-end"><strong>{{ $receiptsCount }}</strong></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="section">
        <strong class="title">{{ __('CASH RECEIVED') }}</strong>
        {{ __('Cash from sales') }} &nbsp; {{ $fmt($cashTotal ?? 0) }}<br>
        @if(isset($cashFromBalance) && $cashFromBalance > 0)
            {{ __('Cash from balance payments') }} &nbsp; {{ $fmt($cashFromBalance) }}<br>
        @endif
        @if(!empty($receivedCreditPayments) && count($receivedCreditPayments) > 0)
            {{ __('pos.credit_received_label') ?: 'Credit Received' }}:<br>
            @foreach($receivedCreditPayments as $rp)
                {{ __('Invoice') }}: {{ $rp['invoice_id'] }} &nbsp; {{ $rp['payment_method'] }} &nbsp; {{ $fmt($rp['amount']) }}<br>
            @endforeach
        @endif
        {{ __('Total cash received') }} &nbsp; {{ $fmt(($cashTotal ?? 0) + ($cashFromBalance ?? 0)) }}
    </div>

    @if(!empty($accountsReceivableRows) && count($accountsReceivableRows) > 0)
    <div class="section">
        <strong class="title">{{ __('pos.accounts_receivable') }}</strong>
        @foreach($accountsReceivableRows as $ar)
            {{ __('Invoice') }}: {{ $ar['invoice_id'] }} &nbsp;
            {{ __('Total') }}: {{ $fmt($ar['total']) }} &nbsp;
            {{ __('Outstanding') }}: {{ $fmt($ar['outstanding']) }}<br>
        @endforeach
        <strong>{{ __('pos.total_outstanding') }}:</strong> {{ $fmt($accountsReceivableTotal) }}
    </div>
    @endif

    <div class="section">
        <strong class="title">{{ __('CASH REGISTER CHART') }}</strong>
        @foreach($paymentMethodRows as $r)
            {{ $r['name'] }} — {{ $r['count'] }} {{ __('SALES (Positive)') }} &nbsp; {{ $fmt($r['amount']) }}<br>
        @endforeach
        {{ __('TOTAL SALES') }} &nbsp; {{ $fmt($totalSales) }}
    </div>

    <div class="section">
        <strong class="title">{{ __('SUMMARY') }}</strong>
        <table>
            <thead>
                <tr>
                    <th>{{ __('PAYMENT METHOD') }}</th>
                    <th class="text-end">{{ __('DECLARED.') }}</th>
                    <th class="text-end">{{ __('CALCULATION') }}</th>
                    <th class="text-end">{{ __('DIFFERENCE') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($paymentMethodRows as $r)
                    <tr>
                        <td>{{ $r['name'] }}</td>
                        <td class="text-end">0.00</td>
                        <td class="text-end">{{ $fmt($r['amount']) }}</td>
                        <td class="text-end">{{ $fmt(-$r['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>{{ __('TOTAL') }}</strong></td>
                    <td class="text-end">0.00</td>
                    <td class="text-end"><strong>{{ $fmt($totalSales) }}</strong></td>
                    <td class="text-end"><strong>{{ $fmt(-$totalSales) }}</strong></td>
                </tr>
            </tfoot>
        </table>
        <br>
        {{ __('SALES (Pos.)') }}: {{ $fmt($totalSales) }} &nbsp;
        {{ __('CREDITS') }}: {{ $fmt($creditTotal ?? 0) }} &nbsp;
        {{ __('TOTAL SALES') }}: {{ $fmt($totalSales) }}
    </div>

    <div class="section">
        <strong class="title">{{ __('STATEMENT BY PAYMENT METHOD') }}</strong>
        <table>
            <thead>
                <tr>
                    <th>{{ __('TIKET') }}</th>
                    <th class="text-end">{{ __('COUNT') }}</th>
                    <th>{{ __('TIME') }}</th>
                    <th>{{ __('Employee') }}</th>
                    <th class="text-end">{{ __('AMOUNT') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($statementRows as $r)
                    <tr>
                        <td>{{ $r['ticket'] }}</td>
                        <td class="text-end">{{ $r['count'] }}</td>
                        <td>{{ $r['time'] }}</td>
                        <td>{{ $r['employee'] }}</td>
                        <td class="text-end">{{ $fmt($r['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4"></td>
                    <td class="text-end"><strong>{{ $fmt($totalSales) }}</strong></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="section">
        <strong class="title">{{ __('SALES PER EMPLOYEE') }}</strong>
        <table>
            <thead>
                <tr>
                    <th>{{ __('Employee') }}</th>
                    <th class="text-end">{{ __('AMOUNT') }}</th>
                    <th class="text-end">N.O</th>
                </tr>
            </thead>
            <tbody>
                @foreach($salesPerEmployee as $r)
                    <tr>
                        <td>{{ $r['name'] }}</td>
                        <td class="text-end">{{ $fmt($r['amount']) }}</td>
                        <td class="text-end">{{ $r['count'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td class="text-end"><strong>{{ $fmt($totalSales) }}</strong></td>
                    <td class="text-end"><strong>{{ $receiptsCount }}</strong></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="section">
        <strong class="title">{{ __('SALES SUMMARY') }}</strong>
        {{ __('SALES') }}: {{ $fmt($totalSales) }}<br>
        {{ __('NUMBER OF RECEIPTS') }}: {{ $receiptsCount }}<br>
        {{ __('NUMBER CUSTOMERS') }}: {{ $customersCount }}<br>
        {{ __('Average X Receipt') }}: {{ $fmt($avgPerReceipt) }}<br>
        {{ __('Average X Customer') }}: {{ $fmt($avgPerCustomer) }}
    </div>

    @if(count($categorySummary) > 0)
        <div class="section">
            <strong class="title">{{ __('SALES BY SECTION') }}</strong>
            @php $totalCat = array_sum(array_map(fn($c) => $c['amount'], $categorySummary)); @endphp
            <table>
                <thead>
                    <tr>
                        <th>{{ __('SECTION') }}</th>
                        <th class="text-end">N. ART</th>
                        <th class="text-end">{{ __('AMOUNT') }}</th>
                        <th class="text-end">%</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($categorySummary as $catName => $row)
                        @php $pct = $totalCat > 0 ? round($row['amount'] / $totalCat * 100, 2) : 0; @endphp
                        <tr>
                            <td>{{ $catName }}</td>
                            <td class="text-end">{{ number_format($row['qty'], 2) }}</td>
                            <td class="text-end">{{ $fmt($row['amount']) }}</td>
                            <td class="text-end">{{ $pct }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(count($taxSummary) > 0)
        <div class="section">
            <strong class="title">{{ __('TAXES') }}</strong>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('TAX') }}</th>
                        <th class="text-end">{{ __('BASE') }}</th>
                        <th class="text-end">{{ __('QUOTA') }}</th>
                        <th class="text-end">{{ __('TOTAL') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($taxSummary as $taxName => $r)
                        <tr>
                            <td>{{ $taxName }}</td>
                            <td class="text-end">{{ $fmt($r['base']) }}</td>
                            <td class="text-end">{{ $fmt($r['quota']) }}</td>
                            <td class="text-end">{{ $fmt($r['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(count($itemsSold) > 0)
        <div class="section">
            <strong class="title">{{ __('SUMMARY OF ITEMS SOLD') }}</strong>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('ITEM') }}</th>
                        <th class="text-end">QTY</th>
                        <th class="text-end">{{ __('AMOUNT') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($itemsSold as $itemName => $row)
                        <tr>
                            <td>{{ $itemName }}</td>
                            <td class="text-end">{{ number_format($row['qty'], 2) }}</td>
                            <td class="text-end">{{ $fmt($row['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td></td>
                        <td class="text-end"><strong>{{ number_format(array_sum(array_column($itemsSold, 'qty')), 2) }}</strong></td>
                        <td class="text-end"><strong>{{ $fmt($totalSales) }}</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</body>
</html>
