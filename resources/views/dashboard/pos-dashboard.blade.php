@extends('layouts.admin')
@section('page-title')
    {{__('Dashboard')}}
@endsection

@push('css-page')
<style>
    .apexcharts-yaxis
    {
        transform: translate(20px, 0px) !important;
    }
</style>
@endpush

@push('script-page')
    <script>
        (function() {
            var options = {
                chart: {
                    height: 350,
                    type: 'area',
                    toolbar: {
                        show: false,
                    },
                },
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    width: 2,
                    curve: 'smooth'
                },
                series: [{
                    name: '{{ __('Purchase') }}',
                    data: {!! json_encode($purchasesArray['value']) !!}
                    // data:  [70,270,80,245,115,260,135,280,70,215]

                },
                    {
                        name: '{{ __('POS') }}',
                        data: {!! json_encode($posesArray['value']) !!}

                        // data:  [100,300,100,260,140,290,150,300,100,250]

                    },
                ],
                xaxis: {
                    categories: {!! json_encode($purchasesArray['label']) !!},
                    title: {
                        text: '{{ __('Days') }}'
                    }
                },
                colors: ['#43b536', '#6fd943'],

                grid: {
                    strokeDashArray: 4,
                },
                legend: {
                    show: false,
                },
                // markers: {
                //     size: 4,
                //     colors: ['#ffa21d', '#43b536'],
                //     opacity: 0.9,
                //     strokeWidth: 2,
                //     hover: {
                //         size: 7,
                //     }
                // },
                yaxis: {
                    title: {
                        text: '{{ __('Amount') }}',
                        offsetX: 30,
                        offsetY: -35,
                    },
                }
            };
            var chart = new ApexCharts(document.querySelector("#traffic-chart"), options);
            chart.render();
        })();

    </script>
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('POS')}}</li>
@endsection
@section('content')
<style>
/*    .modern-card {*/
/*    position: relative;*/
/*    padding: 22px 24px;*/
/*    border-radius: 18px;*/
/*    background: linear-gradient(135deg, #ffffff, #f8fafc);*/
/*    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);*/
/*    transition: all 0.35s ease;*/
/*    overflow: hidden;*/
/*}*/

.modern-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
}

.card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}
.modern-card .card-title{
    margin-bottom: 0px !important;
}
.icon-box {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #fff;
}
.icon-box::before {
    content:'';
}

.stat-number {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
}

.card-title a {
    font-size: 15px;
    font-weight: 500;
    color: #6b7280;
    text-decoration: none;
}

.card-title a:hover {
    color: #111827;
}

/* Gradient Icon Backgrounds */
.bg-customers {
    background: #43b536;
}
.bg-vendors {
    background: #43b536;
}
.bg-invoices {
    background: #43b536;
}
.bg-bills {
    background: #43b536;
}

</style>
    <div class="row gy-4">
        <div class="row g-4 my-2">
            <!-- POS Of This Month -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="modern-card card card-body">
                    <div class="card-top">
                        <div class="icon-box bg-customers">
                            <i class="ti ti-credit-card"></i>
                        </div>
                        <div class="stat-number">{{$pos_data['monthlyPosAmount']}}</div>
                    </div>
                    <div class="card-title">
                        <a href="{{ route('pos.report') }}">POS Of This Month</a>
                    </div>
                </div>
            </div>
        
            <!-- Total POS Amount -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="modern-card card card-body">
                    <div class="card-top">
                        <div class="icon-box bg-customers">
                            <i class="ti ti-cash"></i>
                        </div>
                        <div class="stat-number">{{$pos_data['totalPosAmount']}}</div>
                    </div>
                    <div class="card-title">
                        <a href="{{ route('pos.report') }}">POS Amount</a>
                    </div>
                </div>
            </div>
        
            <!-- Purchase Of This Month -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="modern-card card card-body">
                    <div class="card-top">
                        <div class="icon-box bg-customers">
                            <i class="ti ti-shopping-cart"></i>
                        </div>
                        <div class="stat-number">{{$pos_data['monthlyPurchaseAmount']}}</div>
                    </div>
                    <div class="card-title">
                        <a href="{{ route('purchase.index') }}">Purchase Of This Month</a>
                    </div>
                </div>
            </div>
        
            <!-- Total Purchase Amount -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="modern-card card card-body">
                    <div class="card-top">
                        <div class="icon-box bg-customers">
                            <i class="ti ti-receipt"></i>
                        </div>
                        <div class="stat-number">{{$pos_data['totalPurchaseAmount']}}</div>
                    </div>
                    <div class="card-title">
                        <a href="{{ route('purchase.index') }}">Purchase Amount</a>
                    </div>
                </div>
            </div>
        
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row ">
                        <div class="col-6">
                            <h5>{{ __('Purchase Vs POS Report') }}</h5>
                        </div>
                        <div class="col-6 text-end">
                            <h6>{{ __('Last 10 Days') }}</h6>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div id="traffic-chart"></div>
                </div>
            </div>
        </div>

    </div>
@endsection
