@extends('layouts.admin')
@section('page-title')
    {{__('Dashboard')}}
@endsection
@push('script-page')
    <script>
        @if(\Auth::user()->can('show account dashboard'))
        (function () {
            var chartBarOptions = {
                series: [
                    {
                        name: "{{__('Income')}}",
                        data:{!! json_encode($incExpLineChartData['income']) !!}
                    },
                    {
                        name: "{{__('Expense')}}",
                        data: {!! json_encode($incExpLineChartData['expense']) !!}
                    }
                ],

                chart: {
                    height: 250,
                    type: 'area',
                    // type: 'line',
                    dropShadow: {
                        enabled: true,
                        color: '#000',
                        top: 18,
                        left: 7,
                        blur: 10,
                        opacity: 0.2
                    },
                    toolbar: {
                        show: false
                    }
                },
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    width: 2,
                    curve: 'smooth'
                },
                title: {
                    text: '',
                    align: 'left'
                },
                xaxis: {
                    categories:{!! json_encode($incExpLineChartData['day']) !!},
                    title: {
                        text: '{{ __("Date") }}'
                    }
                },
                colors: ['#6fd944', '#43b536'],


                grid: {
                    strokeDashArray: 4,
                },
                legend: {
                    show: false,
                },
                // markers: {
                //     size: 4,
                //     colors: ['#6fd944', '#43b536'],
                //     opacity: 0.9,
                //     strokeWidth: 2,
                //     hover: {
                //         size: 7,
                //     }
                // },
                yaxis: {
                    title: {
                        text: '{{ __("Amount") }}'
                    },

                }

            };
            var arChart = new ApexCharts(document.querySelector("#cash-flow"), chartBarOptions);
            arChart.render();
        })();

        (function () {
            var options = {
                chart: {
                    height: 180,
                    type: 'bar',
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
                    name: "{{__('Income')}}",
                    data: {!! json_encode($incExpBarChartData['income']) !!}
                }, {
                    name: "{{__('Expense')}}",
                    data: {!! json_encode($incExpBarChartData['expense']) !!}
                }],
                xaxis: {
                    categories: {!! json_encode($incExpBarChartData['month']) !!},
                },
                colors: ['#3ec9d6', '#43b536'],
                fill: {
                    type: 'solid',
                },
                grid: {
                    strokeDashArray: 4,
                },
                legend: {
                    show: true,
                    position: 'top',
                    horizontalAlign: 'right',
                },
                // markers: {
                //     size: 4,
                //     colors:  ['#3ec9d6', '#43b536',],
                //     opacity: 0.9,
                //     strokeWidth: 2,
                //     hover: {
                //         size: 7,
                //     }
                // }
            };
            var chart = new ApexCharts(document.querySelector("#incExpBarChart"), options);
            chart.render();
        })();

        (function () {
            var options = {
                chart: {
                    height: 200,
                    type: 'donut',
                },
                dataLabels: {
                    enabled: false,
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '75%',
                        }
                    }
                },
                series: {!! json_encode($expenseCatAmount) !!},
                colors: {!! json_encode($expenseCategoryColor) !!},
                labels: {!! json_encode($expenseCategory) !!},
                legend: {
                    show: true
                }
            };
            var chart = new ApexCharts(document.querySelector("#expenseByCategory"), options);
            chart.render();
        })();

        (function () {
            var options = {
                chart: {
                    height: 200,
                    type: 'donut',
                },
                dataLabels: {
                    enabled: false,
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '75%',
                        }
                    }
                },
                series: {!! json_encode($incomeCatAmount) !!},
                colors: {!! json_encode($incomeCategoryColor) !!},
                labels:  {!! json_encode($incomeCategory) !!},
                legend: {
                    show: true
                }
            };
            var chart = new ApexCharts(document.querySelector("#incomeByCategory"), options);
            chart.render();
        })();

        (function () {
            var options = {
                series: [{{ round($storage_limit,2) }}],
                chart: {
                    height: 400,
                    type: 'radialBar',
                    offsetY: -20,
                    sparkline: {
                        enabled: true
                    }
                },
                plotOptions: {
                    radialBar: {
                        startAngle: -90,
                        endAngle: 90,
                        track: {
                            background: "#e7e7e7",
                            strokeWidth: '97%',
                            margin: 5, // margin is in pixels
                        },
                        dataLabels: {
                            name: {
                                show: true
                            },
                            value: {
                                offsetY: -50,
                                fontSize: '20px'
                            }
                        }
                    }
                },
                grid: {
                    padding: {
                        top: -10
                    }
                },
                colors: ["#43b536"],
                labels: ['Used'],
            };
            var chart = new ApexCharts(document.querySelector("#limit-chart"), options);
            chart.render();
        })();

        @endif
    </script>
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Account')}}</li>
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
    background: linear-gradient(135deg, #4f46e5, #6366f1);
}
.bg-vendors {
    background: linear-gradient(135deg, #f59e0b, #fbbf24);
}
.bg-invoices {
    background: linear-gradient(135deg, #10b981, #34d399);
}
.bg-bills {
    background: linear-gradient(135deg, #ef4444, #f87171);
}

</style>
    <div class="row">
        <div class="col-sm-12">
            <div class="row">
                <div class="col-xxl-7">
                    <div class="row g-4 my-2">
                        <!-- CARD -->
                        <div class="col-xl-3 col-md-6">
                            <div class="modern-card card card-body">
                                <div class="card-top">
                                    <div class="icon-box bg-customers">
                                        <i class="ti ti-users"></i>
                                    </div>
                                    <div class="stat-number">{{ \Auth::user()->countCustomers() }}</div>
                                </div>
                                <div class="card-title">
                                    <a href="{{ route('customer.index') }}">Total Customers</a>
                                </div>
                            </div>
                        </div>
                    
                        <!-- CARD -->
                        <div class="col-xl-3 col-md-6">
                            <div class="modern-card card card-body">
                                <div class="card-top">
                                    <div class="icon-box bg-vendors">
                                        <i class="ti ti-building-store"></i>
                                    </div>
                                    <div class="stat-number">{{ \Auth::user()->countVenders() }}</div>
                                </div>
                                <div class="card-title">
                                    <a href="{{ route('vender.index') }}">Total Vendors</a>
                                </div>
                            </div>
                        </div>
                    
                        <!-- CARD -->
                        <div class="col-xl-3 col-md-6">
                            <div class="modern-card card card-body">
                                <div class="card-top">
                                    <div class="icon-box bg-invoices">
                                        <i class="ti ti-file-invoice"></i>
                                    </div>
                                    <div class="stat-number">{{ \Auth::user()->countInvoices() }}</div>
                                </div>
                                <div class="card-title">
                                    <a href="{{ route('invoice.index') }}">Total Invoices</a>
                                </div>
                            </div>
                        </div>
                    
                        <!-- CARD -->
                        <div class="col-xl-3 col-md-6">
                            <div class="modern-card card card-body">
                                <div class="card-top">
                                    <div class="icon-box bg-bills">
                                        <i class="ti ti-receipt"></i>
                                    </div>
                                    <div class="stat-number">{{ \Auth::user()->countBills() }}</div>
                                </div>
                                <div class="card-title">
                                    <a href="{{ route('bill.index') }}">Total Bills</a>
                                </div>
                            </div>
                        </div>
                    
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mt-1 mb-0">{{__('Account Balance')}}</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th>{{__('Bank')}}</th>
                                        <th>{{__('Holder Name')}}</th>
                                        <th>{{__('Balance')}}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($bankAccountDetail as $bankAccount)

                                        <tr class="font-style">
                                            <td>{{$bankAccount->bank_name}}</td>
                                            <td>{{$bankAccount->holder_name}}</td>
                                            <td>{{\Auth::user()->priceFormat($bankAccount->opening_balance)}}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4">
                                                <div class="text-center">
                                                    <h6>{{__('there is no account balance')}}</h6>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mt-1 mb-0">{{ __('Expiry Alerts') }}</h5>
                        </div>
                        <div class="card-body">
                            @if(!empty($expiringLots) && count($expiringLots) > 0)
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Product') }}</th>
                                                <th>{{ __('Expiry') }}</th>
                                                <th class="text-end">{{ __('Qty') }}</th>
                                                <th class="text-end">{{ __('Days left') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($expiringLots as $lot)
                                                @php
                                                    $daysLeft = \Carbon\Carbon::today()->diffInDays(\Carbon\Carbon::parse($lot->expiry_date), false);
                                                @endphp
                                                <tr>
                                                    <td>{{ $lot->product_name }}{{ $lot->sku ? ' (' . $lot->sku . ')' : '' }}</td>
                                                    <td>{{ \Carbon\Carbon::parse($lot->expiry_date)->format('Y-m-d') }}</td>
                                                    <td class="text-end">{{ $lot->quantity_remaining }}</td>
                                                    <td class="text-end">{{ max(0, (int) $daysLeft) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-muted mb-0">{{ __('No products expiring soon.') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-xxl-5">
                    <div class="card income-card">
                        <div class="card-header header-icon py-3">
                            <h5>{{__('Income Vs Expense')}}</h5>
                        </div>
                        <div class="card-body">
                            <div class="income-card-inner d-flex justify-content-between">
                                <div class="income-card-left">
                                    <div class="income-info iday">
                                        <div class="income-icon-wrp d-flex align-items-center">
                                            <div class="income-icon">
                                                <svg width="19" height="19" viewBox="0 0 19 19" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <g clip-path="url(#clip0_28_708)">
                                                    <path d="M3.03684 12.5408C3.56251 13.0111 4.15863 13.3839 4.79751 13.6642L8.15734 11.5544C8.85242 11.1158 9.78342 11.2195 10.3653 11.8046L11.495 12.9343C13.1884 11.6858 14.25 9.68844 14.25 7.52085C14.25 3.81031 11.2314 0.791687 7.52084 0.791687C3.8103 0.791687 0.791672 3.81031 0.791672 7.52085C0.791672 9.4359 1.60946 11.2654 3.03684 12.5408ZM7.82167 8.1146H7.22001C6.29455 8.1146 5.54167 7.36173 5.54167 6.43627C5.54167 5.60423 6.14176 4.91944 6.92709 4.7801V4.15627C6.92709 3.82852 7.19309 3.56252 7.52084 3.56252C7.84859 3.56252 8.11459 3.82852 8.11459 4.15627V4.75002H8.90626C9.23401 4.75002 9.50001 5.01602 9.50001 5.34377C9.50001 5.67152 9.23401 5.93752 8.90626 5.93752H7.22001C6.94926 5.93752 6.72917 6.1576 6.72917 6.42835C6.72917 6.70702 6.94926 6.9271 7.22001 6.9271H7.82167C8.74713 6.9271 9.50001 7.67998 9.50001 8.60544C9.50001 9.43748 8.89992 10.1223 8.11459 10.2616V10.8846C8.11459 11.2124 7.84859 11.4784 7.52084 11.4784C7.19309 11.4784 6.92709 11.2124 6.92709 10.8846V10.2917H6.13542C5.80767 10.2917 5.54167 10.0257 5.54167 9.69794C5.54167 9.37019 5.80767 9.10419 6.13542 9.10419H7.82167C8.09242 9.10419 8.31251 8.8841 8.31251 8.61335C8.31251 8.33469 8.09242 8.1146 7.82167 8.1146Z" fill="white"/>
                                                    <path d="M18.2083 9.5H15.0417C14.7218 9.5 14.4321 9.69317 14.3102 9.98846C14.1875 10.2845 14.2555 10.625 14.482 10.8514L15.5056 11.875L12.2708 15.1098L9.66388 12.5028C9.405 12.2423 8.99809 12.1972 8.68775 12.3896L0.375252 17.5354C0.00316851 17.7658 -0.110832 18.2535 0.118752 18.6247C0.268377 18.867 0.527252 19 0.79246 19C0.93496 19 1.07825 18.962 1.20809 18.8813L8.987 14.0655L11.7103 16.7889C12.0199 17.0984 12.5202 17.0984 12.8298 16.7889L16.625 12.9944L17.6486 14.018C17.7998 14.1693 18.0025 14.25 18.2083 14.25C18.3105 14.25 18.4134 14.2302 18.5115 14.1898C18.8076 14.0671 19 13.7782 19 13.4583V10.2917C19 9.85467 18.6461 9.5 18.2083 9.5Z" fill="white"/>
                                                    </g>
                                                    <defs>
                                                    <clipPath id="clip0_28_708">
                                                    <rect width="19" height="19" fill="white"/>
                                                    </clipPath>
                                                    </defs>
                                                </svg>
                                            </div>
                                            <div class="income-text">
                                                <span>{{__('Income Today')}}</span>
                                            </div>
                                        </div>
                                        <h4 class="mb-2">{{\Auth::user()->priceFormat(\Auth::user()->todayIncome())}}</h4>
                                        <div class="progress-line"></div>
                                    </div>
                                    <div class="income-info imonth">
                                        <div class="income-icon-wrp d-flex align-items-center">
                                            <div class="income-icon">
                                                <svg width="19" height="19" viewBox="0 0 19 19" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <g clip-path="url(#clip0_28_708)">
                                                    <path d="M3.03684 12.5408C3.56251 13.0111 4.15863 13.3839 4.79751 13.6642L8.15734 11.5544C8.85242 11.1158 9.78342 11.2195 10.3653 11.8046L11.495 12.9343C13.1884 11.6858 14.25 9.68844 14.25 7.52085C14.25 3.81031 11.2314 0.791687 7.52084 0.791687C3.8103 0.791687 0.791672 3.81031 0.791672 7.52085C0.791672 9.4359 1.60946 11.2654 3.03684 12.5408ZM7.82167 8.1146H7.22001C6.29455 8.1146 5.54167 7.36173 5.54167 6.43627C5.54167 5.60423 6.14176 4.91944 6.92709 4.7801V4.15627C6.92709 3.82852 7.19309 3.56252 7.52084 3.56252C7.84859 3.56252 8.11459 3.82852 8.11459 4.15627V4.75002H8.90626C9.23401 4.75002 9.50001 5.01602 9.50001 5.34377C9.50001 5.67152 9.23401 5.93752 8.90626 5.93752H7.22001C6.94926 5.93752 6.72917 6.1576 6.72917 6.42835C6.72917 6.70702 6.94926 6.9271 7.22001 6.9271H7.82167C8.74713 6.9271 9.50001 7.67998 9.50001 8.60544C9.50001 9.43748 8.89992 10.1223 8.11459 10.2616V10.8846C8.11459 11.2124 7.84859 11.4784 7.52084 11.4784C7.19309 11.4784 6.92709 11.2124 6.92709 10.8846V10.2917H6.13542C5.80767 10.2917 5.54167 10.0257 5.54167 9.69794C5.54167 9.37019 5.80767 9.10419 6.13542 9.10419H7.82167C8.09242 9.10419 8.31251 8.8841 8.31251 8.61335C8.31251 8.33469 8.09242 8.1146 7.82167 8.1146Z" fill="white"/>
                                                    <path d="M18.2083 9.5H15.0417C14.7218 9.5 14.4321 9.69317 14.3102 9.98846C14.1875 10.2845 14.2555 10.625 14.482 10.8514L15.5056 11.875L12.2708 15.1098L9.66388 12.5028C9.405 12.2423 8.99809 12.1972 8.68775 12.3896L0.375252 17.5354C0.00316851 17.7658 -0.110832 18.2535 0.118752 18.6247C0.268377 18.867 0.527252 19 0.79246 19C0.93496 19 1.07825 18.962 1.20809 18.8813L8.987 14.0655L11.7103 16.7889C12.0199 17.0984 12.5202 17.0984 12.8298 16.7889L16.625 12.9944L17.6486 14.018C17.7998 14.1693 18.0025 14.25 18.2083 14.25C18.3105 14.25 18.4134 14.2302 18.5115 14.1898C18.8076 14.0671 19 13.7782 19 13.4583V10.2917C19 9.85467 18.6461 9.5 18.2083 9.5Z" fill="white"/>
                                                    </g>
                                                    <defs>
                                                    <clipPath id="clip0_28_708">
                                                    <rect width="19" height="19" fill="white"/>
                                                    </clipPath>
                                                    </defs>
                                                </svg>
                                            </div>
                                            <div class="income-text">
                                                <span>{{__('Income This Month')}}</span>
                                            </div>
                                        </div>
                                        <h4 class="mb-2">{{\Auth::user()->priceFormat(\Auth::user()->incomeCurrentMonth())}}</h4>
                                        <div class="progress-line"></div>
                                    </div>
                                </div>
                                <div class="income-card-right">
                                    <div class="income-info eday">
                                        <div class="income-icon-wrp d-flex align-items-center">
                                            <div class="income-icon">
                                                <svg width="18" height="19" viewBox="0 0 18 19" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M11.6719 12.7656C11.6719 12.2517 11.6719 10.6875 13.1562 10.6875H16.125V8.90625C16.125 8.08777 15.4591 7.42188 14.6406 7.42188H1.875C1.05652 7.42188 0.390625 8.08777 0.390625 8.90625V16.625C0.390625 17.4435 1.05652 18.1094 1.875 18.1094H14.6406C15.4591 18.1094 16.125 17.4435 16.125 16.625V14.8438H13.1562C11.6719 14.8438 11.6719 13.2795 11.6719 12.7656Z" fill="white"/>
                                                <path d="M12.2656 6.82811V5.02846C12.2656 4.72178 12.1056 4.44361 11.8375 4.28449C11.5597 4.11942 11.2242 4.11378 10.9407 4.26846L5.8074 6.82811H12.2656Z" fill="white"/>
                                                <path d="M14.0469 13.3594C14.3748 13.3594 14.6406 13.0935 14.6406 12.7656C14.6406 12.4377 14.3748 12.1719 14.0469 12.1719C13.719 12.1719 13.4531 12.4377 13.4531 12.7656C13.4531 13.0935 13.719 13.3594 14.0469 13.3594Z" fill="white"/>
                                                <path d="M9.59376 4.27681V3.01301C9.59376 2.56147 9.31588 2.27469 9.05612 2.15683C8.74084 2.01373 8.39527 2.06658 8.13165 2.29784L2.96127 6.82815H4.47712L9.59376 4.27681Z" fill="white"/>
                                                <path d="M16.7188 11.2812H13.1562C12.6216 11.2812 12.2656 11.5339 12.2656 12.7656C12.2656 13.9974 12.6216 14.25 13.1562 14.25H16.7188C17.2098 14.25 17.6094 13.8504 17.6094 13.3594V12.1719C17.6094 11.6808 17.2098 11.2812 16.7188 11.2812ZM14.0469 13.9531C13.392 13.9531 12.8594 13.4205 12.8594 12.7656C12.8594 12.1107 13.392 11.5781 14.0469 11.5781C14.7018 11.5781 15.2344 12.1107 15.2344 12.7656C15.2344 13.4205 14.7018 13.9531 14.0469 13.9531Z" fill="white"/>
                                                <path d="M17.8406 2.61191L16.475 0.774847C16.2981 0.536753 15.9412 0.536753 15.7643 0.775144L14.407 2.603C14.2719 2.73808 14.3675 2.96875 14.5584 2.96875H15.2287C15.4101 3.85819 15.0105 4.76544 14.2321 5.23242L14.0469 5.34375L15.2344 6.23438L15.2866 6.19964C16.3667 5.47942 17.0156 4.26699 17.0156 2.96875H17.6928C17.8792 2.96875 17.9725 2.74372 17.8406 2.61191Z" fill="white"/>
                                                </svg>
                                            </div>
                                            <div class="income-text">
                                                <span>{{__('Expense Today')}}</span>
                                            </div>
                                        </div>
                                        <h4 class="mb-2">{{\Auth::user()->priceFormat(\Auth::user()->todayExpense())}}</h4>
                                        <div class="progress-line"></div>
                                    </div>
                                    <div class="income-info emonth">
                                        <div class="income-icon-wrp d-flex align-items-center">
                                            <div class="income-icon">
                                                <svg width="18" height="19" viewBox="0 0 18 19" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M11.6719 12.7656C11.6719 12.2517 11.6719 10.6875 13.1562 10.6875H16.125V8.90625C16.125 8.08777 15.4591 7.42188 14.6406 7.42188H1.875C1.05652 7.42188 0.390625 8.08777 0.390625 8.90625V16.625C0.390625 17.4435 1.05652 18.1094 1.875 18.1094H14.6406C15.4591 18.1094 16.125 17.4435 16.125 16.625V14.8438H13.1562C11.6719 14.8438 11.6719 13.2795 11.6719 12.7656Z" fill="white"/>
                                                <path d="M12.2656 6.82811V5.02846C12.2656 4.72178 12.1056 4.44361 11.8375 4.28449C11.5597 4.11942 11.2242 4.11378 10.9407 4.26846L5.8074 6.82811H12.2656Z" fill="white"/>
                                                <path d="M14.0469 13.3594C14.3748 13.3594 14.6406 13.0935 14.6406 12.7656C14.6406 12.4377 14.3748 12.1719 14.0469 12.1719C13.719 12.1719 13.4531 12.4377 13.4531 12.7656C13.4531 13.0935 13.719 13.3594 14.0469 13.3594Z" fill="white"/>
                                                <path d="M9.59376 4.27681V3.01301C9.59376 2.56147 9.31588 2.27469 9.05612 2.15683C8.74084 2.01373 8.39527 2.06658 8.13165 2.29784L2.96127 6.82815H4.47712L9.59376 4.27681Z" fill="white"/>
                                                <path d="M16.7188 11.2812H13.1562C12.6216 11.2812 12.2656 11.5339 12.2656 12.7656C12.2656 13.9974 12.6216 14.25 13.1562 14.25H16.7188C17.2098 14.25 17.6094 13.8504 17.6094 13.3594V12.1719C17.6094 11.6808 17.2098 11.2812 16.7188 11.2812ZM14.0469 13.9531C13.392 13.9531 12.8594 13.4205 12.8594 12.7656C12.8594 12.1107 13.392 11.5781 14.0469 11.5781C14.7018 11.5781 15.2344 12.1107 15.2344 12.7656C15.2344 13.4205 14.7018 13.9531 14.0469 13.9531Z" fill="white"/>
                                                <path d="M17.8406 2.61191L16.475 0.774847C16.2981 0.536753 15.9412 0.536753 15.7643 0.775144L14.407 2.603C14.2719 2.73808 14.3675 2.96875 14.5584 2.96875H15.2287C15.4101 3.85819 15.0105 4.76544 14.2321 5.23242L14.0469 5.34375L15.2344 6.23438L15.2866 6.19964C16.3667 5.47942 17.0156 4.26699 17.0156 2.96875H17.6928C17.8792 2.96875 17.9725 2.74372 17.8406 2.61191Z" fill="white"/>
                                                </svg>
                                            </div>
                                            <div class="income-text">
                                                <span>{{__('Expense This Month')}}</span>
                                            </div>
                                        </div>
                                        <h4 class="mb-2">{{\Auth::user()->priceFormat(\Auth::user()->expenseCurrentMonth())}}</h4>
                                        <div class="progress-line"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card" style="height: 370px">
                        <div class="card-header">
                            <h5 class="mt-1 mb-0">{{__('Cashflow')}}</h5>
                        </div>
                        <div class="card-body">
                            <div id="cash-flow"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-7">
                    <div class="card">
                        <div class="card-header">
                            <h5>{{__('Income & Expense')}}
                                <span class="float-end text-muted">{{__('Current Year').' - '.$currentYear}}</span>
                            </h5>

                        </div>
                        <div class="card-body">
                            <div id="incExpBarChart"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-5">
                    <div class="card" style="height: 315px">
                        <div class="card-header">
                            <h5>{{__('Income By Category')}}
                                <span class="float-end text-muted">{{__('Year').' - '.$currentYear}}</span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div id="incomeByCategory"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mt-1 mb-0">{{__('Latest Income')}}</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th>{{__('Date')}}</th>
                                        <th>{{__('Customer')}}</th>
                                        <th>{{__('Amount Due')}}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($latestIncome as $income)
                                        <tr>
                                            <td>{{\Auth::user()->dateFormat($income->date)}}</td>
                                            <td>{{!empty($income->customer)?$income->customer->name:'-'}}</td>
                                            <td>{{\Auth::user()->priceFormat($income->amount)}}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4">
                                                <div class="text-center">
                                                    <h6>{{__('There is no latest income')}}</h6>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-5">
                    <div class="card" style="height: 370px">
                        <div class="card-header">
                            <h5>{{__('Expense By Category')}}
                                <span class="float-end text-muted">{{__('Year').' - '.$currentYear}}</span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div id="expenseByCategory"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mt-1 mb-0">{{__('Latest Expense')}}</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th>{{__('Date')}}</th>
                                        <th>{{__('Vendor')}}</th>
                                        <th>{{__('Amount Due')}}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($latestExpense as $expense)

                                        <tr>
                                            <td>{{\Auth::user()->dateFormat($expense->date)}}</td>
                                            <td>{{!empty($expense->vender)?$expense->vender->name:'-'}}</td>
                                            <td>{{\Auth::user()->priceFormat($expense->amount)}}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4">
                                                <div class="text-center">
                                                    <h6>{{__('There is no latest expense')}}</h6>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-5">
                    <div class="card" style="height: 370px">
                        <div class="card-header">
                            <h5>{{__('Storage Limit')}}
                                <small class="float-end text-muted">{{ $users->storage_limit . 'MB' }} / {{ $plan->storage_limit . 'MB' }}</small>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div id="limit-chart"></div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mt-1 mb-0">{{__('Recent Invoices')}}</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>{{__('Customer')}}</th>
                                        <th>{{__('Issue Date')}}</th>
                                        <th>{{__('Due Date')}}</th>
                                        <th>{{__('Amount')}}</th>
                                        <th>{{__('Status')}}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($recentInvoice as $invoice)
                                        <tr>
                                            <td>{{\Auth::user()->invoiceNumberFormat($invoice->invoice_id)}}</td>
                                            <td>{{!empty($invoice->customer_name)? $invoice->customer_name:'' }} </td>
                                            <td>{{ Auth::user()->dateFormat($invoice->issue_date) }}</td>
                                            <td>{{ Auth::user()->dateFormat($invoice->due_date) }}</td>
                                            <td>{{\Auth::user()->priceFormat($invoice->getTotal())}}</td>
                                            <td>
                                                @if($invoice->status == 0)
                                                    <span class="p-2 px-3 rounded badge status_badge bg-secondary">{{ __(\App\Models\Invoice::$statues[$invoice->status]) }}</span>
                                                @elseif($invoice->status == 1)
                                                    <span class="p-2 px-3 rounded badge status_badge bg-warning">{{ __(\App\Models\Invoice::$statues[$invoice->status]) }}</span>
                                                @elseif($invoice->status == 2)
                                                    <span class="p-2 px-3 rounded badge status_badge bg-danger">{{ __(\App\Models\Invoice::$statues[$invoice->status]) }}</span>
                                                @elseif($invoice->status == 3)
                                                    <span class="p-2 px-3 rounded badge status_badge bg-info">{{ __(\App\Models\Invoice::$statues[$invoice->status]) }}</span>
                                                @elseif($invoice->status == 4)
                                                    <span class="p-2 px-3 rounded badge status_badge bg-primary">{{ __(\App\Models\Invoice::$statues[$invoice->status]) }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6">
                                                <div class="text-center">
                                                    <h6>{{__('There is no recent invoice')}}</h6>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-5">
                    <div class="card" style="height: 395px">
                        <div class="card-body">
                            <ul class="nav nav-pills mb-5" id="pills-tab" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="pills-home-tab" data-bs-toggle="pill" href="#invoice_weekly_statistics" role="tab" aria-controls="pills-home" aria-selected="true">{{__('Invoices Weekly Statistics')}}</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="pills-profile-tab" data-bs-toggle="pill" href="#invoice_monthly_statistics" role="tab" aria-controls="pills-profile" aria-selected="false">{{__('Invoices Monthly Statistics')}}</a>
                                </li>
                            </ul>
                            <div class="tab-content" id="pills-tabContent">
                                <div class="tab-pane fade show active" id="invoice_weekly_statistics" role="tabpanel" aria-labelledby="pills-home-tab">
                                    <div class="table-responsive">
                                        <table class="table align-items-center mb-0 ">
                                            <tbody class="list">
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Invoice Generated')}}</p>

                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($weeklyInvoice['invoiceTotal'])}}</h4>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Paid')}}</p>
                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($weeklyInvoice['invoicePaid'])}}</h4>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Due')}}</p>
                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($weeklyInvoice['invoiceDue'])}}</h4>
                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="invoice_monthly_statistics" role="tabpanel" aria-labelledby="pills-profile-tab">
                                    <div class="table-responsive">
                                        <table class="table align-items-center mb-0 ">
                                            <tbody class="list">
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Invoice Generated')}}</p>

                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($monthlyInvoice['invoiceTotal'])}}</h4>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Paid')}}</p>
                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($monthlyInvoice['invoicePaid'])}}</h4>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Due')}}</p>
                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($monthlyInvoice['invoiceDue'])}}</h4>
                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mt-1 mb-0">{{__('Recent Bills')}}</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>{{__('Vendor')}}</th>
                                        <th>{{__('Bill Date')}}</th>
                                        <th>{{__('Due Date')}}</th>
                                        <th>{{__('Amount')}}</th>
                                        <th>{{__('Status')}}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse($recentBill as $bill)
                                        <tr>
                                            <td>{{\Auth::user()->billNumberFormat($bill->bill_id)}}</td>
                                            <td>{{!empty($bill->vender_name)? $bill->vender_name : '-' }} </td>
                                            <td>{{ Auth::user()->dateFormat($bill->bill_date) }}</td>
                                            <td>{{ Auth::user()->dateFormat($bill->due_date) }}</td>
                                            <td>{{\Auth::user()->priceFormat($bill->getTotal())}}</td>
                                            <td>
                                                @if($bill->status == 0)
                                                    <span class="p-2 px-3 status_badge rounded badge bg-secondary">{{ __(\App\Models\Bill::$statues[$bill->status]) }}</span>
                                                @elseif($bill->status == 1)
                                                    <span class="p-2 px-3 status_badge rounded badge bg-warning">{{ __(\App\Models\Bill::$statues[$bill->status]) }}</span>
                                                @elseif($bill->status == 2)
                                                    <span class="p-2 px-3 status_badge rounded badge bg-danger">{{ __(\App\Models\Bill::$statues[$bill->status]) }}</span>
                                                @elseif($bill->status == 3)
                                                    <span class="p-2 px-3 status_badge rounded badge bg-info">{{ __(\App\Models\Bill::$statues[$bill->status]) }}</span>
                                                @elseif($bill->status == 4)
                                                    <span class="p-2 px-3 status_badge rounded badge bg-primary">{{ __(\App\Models\Bill::$statues[$bill->status]) }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6">
                                                <div class="text-center">
                                                    <h6>{{__('There is no recent bill')}}</h6>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-5">
                    <div class="card" style="height: 395px">
                        <div class="card-body">
                            <ul class="nav nav-pills mb-5" id="pills-tab" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="pills-home-tab" data-bs-toggle="pill" href="#bills_weekly_statistics" role="tab" aria-controls="pills-home" aria-selected="true">{{__('Bills Weekly Statistics')}}</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="pills-profile-tab" data-bs-toggle="pill" href="#bills_monthly_statistics" role="tab" aria-controls="pills-profile" aria-selected="false">{{__('Bills Monthly Statistics')}}</a>
                                </li>
                            </ul>
                            <div class="tab-content" id="pills-tabContent">
                                <div class="tab-pane fade show active" id="bills_weekly_statistics" role="tabpanel" aria-labelledby="pills-home-tab">
                                    <div class="table-responsive">
                                        <table class="table align-items-center mb-0 ">
                                            <tbody class="list">
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Bill Generated')}}</p>

                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($weeklyBill['billTotal'])}}</h4>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Paid')}}</p>
                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($weeklyBill['billPaid'])}}</h4>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Due')}}</p>
                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($weeklyBill['billDue'])}}</h4>
                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="bills_monthly_statistics" role="tabpanel" aria-labelledby="pills-profile-tab">
                                    <div class="table-responsive">
                                        <table class="table align-items-center mb-0 ">
                                            <tbody class="list">
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Bill Generated')}}</p>

                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($monthlyBill['billTotal'])}}</h4>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Paid')}}</p>
                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($monthlyBill['billPaid'])}}</h4>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <h5 class="mb-0">{{__('Total')}}</h5>
                                                    <p class="text-muted text-sm mb-0">{{__('Due')}}</p>
                                                </td>
                                                <td>
                                                    <h4>{{\Auth::user()->priceFormat($monthlyBill['billDue'])}}</h4>
                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xxl-12">
                    <div class="card">
                        <div class="card-header">
                            <h5>{{__('Goal')}}</h5>
                        </div>
                        <div class="card-body">
                            @forelse($goals as $goal)
                                @php
                                    $total= $goal->target($goal->type,$goal->from,$goal->to,$goal->amount)['total'];
                                    $percentage=$goal->target($goal->type,$goal->from,$goal->to,$goal->amount)['percentage'];
                                    $per=number_format($goal->target($goal->type,$goal->from,$goal->to,$goal->amount)['percentage'], Utility::getValByName('decimal_number'), '.', '');
                                @endphp
                                <div class="card border-success border-2 border-bottom-0 border-start-0 border-end-0">
                                    <div class="card-body">
                                        <div class="form-check p-0">
                                            <label class="form-check-label d-block" for="customCheckdef1">
                                                <span>
                                                    <span class="row align-items-center">
                                                        <span class="col">
                                                            <span class="text-muted text-sm d-block mb-1">{{__('Name')}}</span>
                                                            <h6 class="text-nowrap mb-3 mb-sm-0">{{$goal->name}}</h6>
                                                        </span>
                                                        <span class="col">
                                                            <span class="text-muted text-sm d-block mb-1">{{__('Type')}}</span>
                                                            <h6 class="mb-3 mb-sm-0">{{ __(\App\Models\Goal::$goalType[$goal->type]) }}</h6>
                                                        </span>
                                                        <span class="col">
                                                            <span class="text-muted text-sm d-block mb-1">{{__('Duration')}}</span>
                                                            <h6 class="mb-3 mb-sm-0">{{$goal->from .' To '.$goal->to}}</h6>
                                                        </span>
                                                        <span class="col">
                                                            <span class="text-muted text-sm d-block mb-1">{{__('Target')}}</span>
                                                            <h6 class="mb-3 mb-sm-0">{{\Auth::user()->priceFormat($total).' of '. \Auth::user()->priceFormat($goal->amount)}}</h6>
                                                        </span>
                                                        <span class="col">
                                                            <span class="text-muted text-sm d-block mb-1">{{__('Progress')}}</span>
                                                            <h6 class="mb-2 d-block">{{number_format($goal->target($goal->type,$goal->from,$goal->to,$goal->amount)['percentage'], Utility::getValByName('decimal_number'), '.', '')}}%</h6>
                                                            <div class="progress mb-0">
                                                                @if($per<=33)
                                                                    <div class="progress-bar bg-danger" style="width: {{$per}}%"></div>
                                                                @elseif($per>=33 && $per<=66)
                                                                    <div class="progress-bar bg-warning" style="width: {{$per}}%"></div>
                                                                @else
                                                                    <div class="progress-bar bg-primary" style="width: {{$per}}%"></div>
                                                                @endif
                                                            </div>
                                                        </span>
                                                    </span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                @empty
                                <div class="card pb-0">
                                    <div class="card-body text-center">
                                        <h6>{{__('There is no goal.')}}</h6>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
    <script>
        if(window.innerWidth <= 500)
        {
            $('p').removeClass('text-sm');
        }
    </script>
@endpush
