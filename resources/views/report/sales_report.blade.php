@extends('layouts.admin')
@section('page-title')
    {{ __('Sales Report') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Sales Report') }}</li>
@endsection

@push('script-page')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script type="text/javascript" src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
    <script>
        function saveAsPDF() {
            var printContents = document.getElementById('printableArea').innerHTML;
            var originalContents = document.body.innerHTML;
            document.body.innerHTML = printContents;
            window.print();
            document.body.innerHTML = originalContents;
        }
    </script>
    <script>
        $(document).ready(function() {
            var report = $('.report').val() || '#item';
            $('ul.nav-pills a[href="' + report + '"]').addClass('active').tab('show');
            $("ul.nav-pills > li > a").click(function() {
                var r = $(this).attr('href');
                $('.report').val(r);
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var dailyData = {!! $salesTrendDailyJson !!};
            var monthlyData = {!! $salesTrendMonthlyJson !!};
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js not loaded');
                return;
            }

            function destroyChart() {
                try {
                    if (window.salesTrendChart && typeof window.salesTrendChart.destroy === 'function') {
                        window.salesTrendChart.destroy();
                    }
                    window.salesTrendChart = null;
                } catch (e) { window.salesTrendChart = null; }
            }

            function renderChart(labels, values, canvasId) {
                var ctx = document.getElementById(canvasId);
                if (!ctx) return;
                destroyChart();
                window.salesTrendChart = new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels.length ? labels : [''],
                        datasets: [{
                            label: '{{ __("Sales") }}',
                            data: values.length ? values : [0],
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            fill: true,
                            tension: 0.3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            }

            function showDaily() {
                var labels = dailyData.map(function(d) { return d.date; });
                var values = dailyData.map(function(d) { return d.amount; });
                renderChart(labels, values, 'salesTrendChart');
            }
            function showMonthly() {
                var labels = monthlyData.map(function(d) { return d.month; });
                var values = monthlyData.map(function(d) { return d.amount; });
                renderChart(labels, values, 'salesTrendChart');
            }

            showMonthly();

            $(document).ready(function() {
                $('#btnTrendDaily').off('click').on('click', function() {
                    $('.sales-trend-btn').removeClass('btn-primary').addClass('btn-secondary');
                    $(this).removeClass('btn-secondary').addClass('btn-primary');
                    showDaily();
                });
                $('#btnTrendMonthly').off('click').on('click', function() {
                    $('.sales-trend-btn').removeClass('btn-primary').addClass('btn-secondary');
                    $(this).removeClass('btn-secondary').addClass('btn-primary');
                    showMonthly();
                });
            });
        });
    </script>
@endpush

@section('action-btn')
    <div class="float-end">
        <a href="#" onclick="saveAsPDF()" class="btn btn-sm btn-primary-subtle me-1" data-bs-toggle="tooltip"
            title="{{ __('Print') }}" data-original-title="{{ __('Print') }}"><i class="ti ti-printer"></i></a>
    </div>
    <div class="float-end me-2">
        {{ Form::open(['route' => ['sales.export'], 'method' => 'POST']) }}
        @csrf
        <input type="hidden" name="start_date" value="{{ $filter['startDateRange'] }}">
        <input type="hidden" name="end_date" value="{{ $filter['endDateRange'] }}">
        <input type="hidden" name="branch_id" value="{{ $filter['branch_id'] ?? 'all' }}">
        <input type="hidden" name="customer_id" value="{{ $filter['customer_id'] ?? 'all' }}">
        <input type="hidden" name="report" class="report" value="#item">
        <button type="submit" class="btn btn-sm btn-secondary" data-bs-toggle="tooltip" title="{{ __('Export') }}"
            data-original-title="{{ __('Export') }}"><i class="ti ti-file-export"></i></button>
        {{ Form::close() }}
    </div>
@endsection

@section('content')
    <div class="mt-4">
        {{ Form::open(['route' => ['report.sales'], 'method' => 'GET', 'id' => 'report_sales']) }}
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6 col-lg-2">
                        <label for="start_date" class="form-label">{{ __('From Date') }}</label>
                        <input type="date" name="start_date" id="start_date" class="form-control"
                            value="{{ $filter['startDateRange'] }}">
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <label for="end_date" class="form-label">{{ __('To Date') }}</label>
                        <input type="date" name="end_date" id="end_date" class="form-control"
                            value="{{ $filter['endDateRange'] }}">
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <label for="branch_id" class="form-label">{{ __('Branch') }}</label>
                        <select name="branch_id" id="branch_id" class="form-select">
                            <option value="all">{{ __('All Branches') }}</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ ($filter['branch_id'] ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <label for="customer_id" class="form-label">{{ __('Customer') }}</label>
                        <select name="customer_id" id="customer_id" class="form-select">
                            <option value="all">{{ __('All Customers') }}</option>
                            <option value="walk_in" {{ ($filter['customer_id'] ?? '') == 'walk_in' ? 'selected' : '' }}>{{ __('Walk-in Customer') }}</option>
                            @foreach($customers as $c)
                                <option value="{{ $c->id }}" {{ ($filter['customer_id'] ?? '') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <button type="submit" class="btn btn-primary w-100">{{ __('Apply Filters') }}</button>
                    </div>
                </div>
            </div>
        </div>
        {{ Form::close() }}

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="p-3 bg-success bg-opacity-10 rounded-3 me-3">
                            <i class="ti ti-currency-dollar ti-md text-success" style="font-size: 24px;"></i>
                        </div>
                        <div>
                            <p class="text-muted mb-0">{{ __('Total Sales') }}</p>
                            <h3 class="mb-0">{{ \Auth::user()->priceFormat($totalSales) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="p-3 bg-primary bg-opacity-10 rounded-3 me-3">
                            <i class="ti ti-shopping-cart ti-md text-white" style="font-size: 24px;"></i>
                        </div>
                        <div>
                            <p class="text-muted mb-0">{{ __('Total Transactions') }}</p>
                            <h3 class="mb-0">{{ $totalTransactions }}</h3>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="p-3 bg-info bg-opacity-10 rounded-3 me-3">
                            <i class="ti ti-trending-up ti-md text-info" style="font-size: 24px;"></i>
                        </div>
                        <div>
                            <p class="text-muted mb-0">{{ __('Avg Transaction') }}</p>
                            <h3 class="mb-0">{{ \Auth::user()->priceFormat($avgTransaction) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h5 class="card-title mb-0">{{ __('Sales Trend') }}</h5>
                <div class="btn-group">
                    <button type="button" class="btn btn-sm btn-secondary sales-trend-btn" id="btnTrendDaily">{{ __('Daily') }}</button>
                    <button type="button" class="btn btn-sm btn-primary sales-trend-btn" id="btnTrendMonthly">{{ __('Monthly') }}</button>
                </div>
            </div>
            <div class="card-body">
                <div class="position-relative" style="height: 300px;">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ __('Top Selling Products') }}</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="border-0 ps-4">{{ __('Product') }}</th>
                                <th class="border-0">{{ __('Quantity Sold') }}</th>
                                <th class="border-0 pe-4">{{ __('Revenue') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topSellingProducts as $row)
                                <tr>
                                    <td class="ps-4 fw-medium">{{ $row['name'] ?? '-' }}</td>
                                    <td>{{ $row['quantity'] ?? 0 }}</td>
                                    <td class="pe-4">{{ \Auth::user()->priceFormat($row['price'] ?? 0) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">{{ __('No data for the selected filters.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- <div class="card" id="invoice-container">
            <div class="card-header">
                <ul class="nav nav-pills gap-2" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="pill" href="#item" role="tab">{{ __('Sales by Item') }}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="pill" href="#customer" role="tab">{{ __('Sales by Customer') }}</a>
                    </li>
                </ul>
            </div>
            <div class="card-body" id="printableArea">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="item" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table pc-dt-simple" id="item-reort">
                                <thead>
                                    <tr>
                                        <th width="33%">{{ __('Invoice Item') }}</th>
                                        <th width="33%">{{ __('Quantity Sold') }}</th>
                                        <th width="33%">{{ __('Amount') }}</th>
                                        <th class="text-end">{{ __('Average Price') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($invoiceItems as $invoiceItem)
                                        <tr>
                                            <td>{{ $invoiceItem['name'] ?? '-' }}</td>
                                            <td>{{ $invoiceItem['quantity'] ?? 0 }}</td>
                                            <td>{{ \Auth::user()->priceFormat($invoiceItem['price'] ?? 0) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($invoiceItem['avg_price'] ?? 0) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="customer" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table pc-dt-simple" id="customer-report">
                                <thead>
                                    <tr>
                                        <th width="33%">{{ __('Customer Name') }}</th>
                                        <th width="33%">{{ __('Invoice Count') }}</th>
                                        <th width="33%">{{ __('Sales') }}</th>
                                        <th class="text-end">{{ __('Sales With Tax') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($invoiceCustomers as $invoiceCustomer)
                                        <tr>
                                            <td>{{ $invoiceCustomer['name'] ?? '-' }}</td>
                                            <td>{{ $invoiceCustomer['invoice_count'] ?? 0 }}</td>
                                            <td>{{ \Auth::user()->priceFormat($invoiceCustomer['price'] ?? 0) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat(($invoiceCustomer['price'] ?? 0) + ($invoiceCustomer['total_tax'] ?? 0)) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div> -->
    </div>
@endsection
