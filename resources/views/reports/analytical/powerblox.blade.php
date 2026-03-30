@extends('layouts.admin')

@section('page-title')
    {{ __('PowerBlox - Analytical Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Analytical Reports') }}</li>
    <li class="breadcrumb-item">{{ __('PowerBlox') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-2">{{ __('PowerBlox management pack (Excel-based)') }}</h5>
                    <p class="text-muted mb-3">
                        {{ __('This section mirrors the client Excel workbook structure. We will gradually map each required sheet/field to ERP data.') }}
                    </p>

                    <div class="alert alert-info">
                        <div class="fw-bold">{{ __('Workbook') }}</div>
                        <div><code>docs/PowerBlox_Reporte_ 2022.xlsm</code></div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="border rounded p-3">
                                <div class="fw-bold mb-2">{{ __('Monthly financial pack') }}</div>
                                <ul class="mb-0">
                                    <li>{{ __('DETALHE GANHOS E GASTOS MENSAL') }}</li>
                                    <li>{{ __('INFORMAÇÃO FINANCEIRA MENSAL') }}</li>
                                    <li>{{ __('GRÁFICOS MENSAL') }}</li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-3">
                                <div class="fw-bold mb-2">{{ __('Taxes / statutory') }}</div>
                                <ul class="mb-0">
                                    <li>{{ __('IMPOSTOS') }}</li>
                                    <li>{{ __('(Will align with Monthly VAT + IRPS reports)') }}</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="text-muted">
                        {{ __('Next step: identify the green cells/fields the client uses monthly and implement them as ERP KPIs here.') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

