@extends('layouts.admin')

@section('page-title')
    {{ __('Management Reports') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Management Reports') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-2">{{ __('Internal / management reports') }}</h5>
                    <p class="text-muted mb-3">{{ __('Existing ERP reports remain here for day-to-day management.') }}</p>

                    <div class="list-group">
                        @can('statement report')
                            <a class="list-group-item list-group-item-action" href="{{ route('report.account.statement') }}">{{ __('Account Statement') }}</a>
                        @endcan
                        @can('invoice report')
                            <a class="list-group-item list-group-item-action" href="{{ route('report.invoice.summary') }}">{{ __('Invoice Summary') }}</a>
                        @endcan
                        <a class="list-group-item list-group-item-action" href="{{ route('report.sales') }}">{{ __('Sales Report') }}</a>
                        <a class="list-group-item list-group-item-action" href="{{ route('report.receivables') }}">{{ __('Receivables') }}</a>
                        <a class="list-group-item list-group-item-action" href="{{ route('report.payables') }}">{{ __('Payables') }}</a>
                        @can('bill report')
                            <a class="list-group-item list-group-item-action" href="{{ route('report.bill.summary') }}">{{ __('Bill Summary') }}</a>
                        @endcan
                        @can('stock report')
                            <a class="list-group-item list-group-item-action" href="{{ route('report.product.stock.report') }}">{{ __('Product Stock') }}</a>
                        @endcan
                        @can('loss & profit report')
                            <a class="list-group-item list-group-item-action" href="{{ route('report.monthly.cashflow') }}">{{ __('Cash Flow') }}</a>
                        @endcan
                        @can('manage transaction')
                            <a class="list-group-item list-group-item-action" href="{{ route('transaction.index') }}">{{ __('Transaction') }}</a>
                        @endcan
                        @can('income report')
                            <a class="list-group-item list-group-item-action" href="{{ route('report.income.summary') }}">{{ __('Income Summary') }}</a>
                        @endcan
                        @can('expense report')
                            <a class="list-group-item list-group-item-action" href="{{ route('report.expense.summary') }}">{{ __('Expense Summary') }}</a>
                        @endcan
                        @can('income vs expense report')
                            <a class="list-group-item list-group-item-action" href="{{ route('report.income.vs.expense.summary') }}">{{ __('Income VS Expense') }}</a>
                        @endcan
                        @can('tax report')
                            <a class="list-group-item list-group-item-action" href="{{ route('report.tax.summary') }}">{{ __('Tax Summary') }}</a>
                        @endcan
                        @can('manage report')
                            <a class="list-group-item list-group-item-action" href="{{ route('reports.credit.note') }}">{{ __('Credit Note Report') }}</a>
                        @endcan
                        @can('manage report')
                            <a class="list-group-item list-group-item-action" href="{{ route('reports.debit.note') }}">{{ __('Debit Note Report') }}</a>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

