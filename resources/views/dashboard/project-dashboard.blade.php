@extends('layouts.admin')
@section('page-title')
    {{__('Dashboard')}}
@endsection
@push('script-page')
    <script>
        (function () {
            var options = {
                chart: {
                    height: 180,
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
                    name: 'Refferal',
                    data:{!! json_encode(array_values($home_data['task_overview'])) !!}
                },],
                xaxis: {
                    categories:{!! json_encode(array_keys($home_data['task_overview'])) !!},
                },
                colors: ['#3ec9d6'],
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
                //     colors: ['#3ec9d6', '#43b536',],
                //     opacity: 0.9,
                //     strokeWidth: 2,
                //     hover: {
                //         size: 7,
                //     }
                // }
            };
            var chart = new ApexCharts(document.querySelector("#task_overview"), options);
            chart.render();
        })();

        (function () {
            var options = {
                chart: {
                    height: 300,
                    type: 'bar',
                    toolbar: {
                        show: false
                    }
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        borderRadius: 10,
                        dataLabels: {
                            position: 'top',
                        },
                    }
                },
                colors: ["#3ec9d6"],
                dataLabels: {
                    enabled: true,
                    offsetX: -6,
                    style: {
                        fontSize: '12px',
                        colors: ['#fff']
                    }
                },
                stroke: {
                    show: true,
                    width: 1,
                    colors: ['#fff']
                },
                grid: {
                    strokeDashArray: 4,
                },
                series: [{
                    data: {!! json_encode(array_values($home_data['timesheet_logged'])) !!}
                }],
                xaxis: {
                    categories: {!! json_encode(array_keys($home_data['timesheet_logged'])) !!},
                },
            };
            var chart = new ApexCharts(document.querySelector("#timesheet_logged"), options);
            chart.render();
        })();

    </script>
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Project')}}</li>
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
<div class="row g-4 my-2">

    <!-- Total Projects -->
    <div class="col-xl-4 col-md-6">
        <div class="modern-card card card-body">
            <div class="card-top">
                <div class="icon-box bg-invoices">
                    <i class="ti ti-folders"></i>
                </div>
                <div class="stat-number">{{ $home_data['total_project']['total'] }}</div>
            </div>
            <div class="card-title d-flex justify-content-between align-items-center">
                <a href="{{ route('projects.index') }}">Total Projects</a>
                <small>{{ $home_data['total_project']['percentage'] }}% Completed</small>
            </div>
        </div>
    </div>

    <!-- Total Tasks -->
    <div class="col-xl-4 col-md-6">
        <div class="modern-card card card-body">
            <div class="card-top">
                <div class="icon-box bg-customers">
                    <i class="ti ti-list-check"></i>
                </div>
                <div class="stat-number">{{ $home_data['total_task']['total'] }}</div>
            </div>
            <div class="card-title d-flex justify-content-between align-items-center">
                <a href="{{ route('taskBoard.view', 'list') }}">Total Tasks</a>
                <small>{{ $home_data['total_task']['percentage'] }}% Completed</small>
            </div>
        </div>
    </div>

    <!-- Total Expense -->
    <div class="col-xl-4 col-md-6">
        <div class="modern-card card card-body">
            <div class="card-top">
                <div class="icon-box bg-bills">
                    <i class="ti ti-wallet"></i>
                </div>
                <div class="stat-number">{{ $home_data['total_expense']['total'] }}</div>
            </div>
            <div class="card-title d-flex justify-content-between align-items-center">
                <span>Total Expense</span>
                <small>{{ $home_data['total_expense']['percentage'] }}% Completed</small>
            </div>
        </div>
    </div>

</div>

    <div class="row">
        <div class="col-lg-4 mb-4">
            <div class="card h-100 mb-0">
                <div class="card-header">

                    <h5>{{__('Project Status')}}</h5>
                </div>
                <div class="card-body">
                    <div class="row gy-5">
                        @foreach($home_data['project_status'] as $status => $val)
                            <div class="col-md-6 col-sm-6">
                                <div class="align-items-start">

                                    <div class="ms-2">
                                        <p class="text-sm mb-2">{{__(\App\Models\Project::$project_status[$status])}}</p>
                                        <h3 class="mb-2 text-{{ \App\Models\Project::$status_color[$status] }}">{{ $val['total'] }}%</h3>
                                        <div class="progress mb-0">
                                            <div class="progress-bar bg-{{ \App\Models\Project::$status_color[$status] }}" style="width: {{$val['percentage']}}%;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5>{{__('Tasks Overview')}} <span class="float-end"> <small class="text-muted">{{__('Total Completed task in last 7 days')}}</small></span></h5>

                </div>
                <div class="card-body">
                    <div id="task_overview"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5>{{__('Top Due Projects')}}</h5>
                </div>
                <div class="card-body project_table">
                    <div class="table-responsive ">
                        <table class="table table-hover mb-0">
                            <thead>
                            <tr>
                                <th>{{__('Name')}}</th>
                                <th>{{__('End Date')}}</th>
                                <th >{{__('Status')}}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @if($home_data['due_project']->count() > 0)
                                @foreach($home_data['due_project'] as $due_project)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <img src="{{asset(Storage::url('/'.$due_project->project_image ))}}"
                                                     class="wid-40 rounded border-2 border border-primary me-3" >
                                                <div>
                                                    <h6 class="mb-0">{{ $due_project->project_name }}</h6>
                                                    <p class="mb-0"><span class="text-success">{{ \Auth::user()->priceFormat($due_project->budget) }}</p>

                                                </div>
                                            </div>
                                        </td>
                                        <td >{{  Utility::getDateFormated($due_project->end_date) }}</td>
                                        <td class="">
                                            <span class=" status_badge p-2 px-3 rounded badge bg-{{\App\Models\Project::$status_color[$due_project->status]}}">{{ __(\App\Models\Project::$project_status[$due_project->status]) }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            @else
                                <tr class="py-5">
                                    <td class="text-center mb-0" colspan="3">{{__('No Due Projects Found.')}}</td>
                                </tr>
                            @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>{{__('Timesheet Logged Hours')}} <span>  <small class="float-end text-muted flo">{{__('Last 7 days')}}</small></span></h5>
                </div>
                <div class="card-body project_table">
                    <div id="timesheet_logged"></div>
                </div>
            </div>
        </div>
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>{{__('Top Due Tasks')}}</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <tbody>
                            @foreach($home_data['due_tasks'] as $due_task)
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="">
                                                <span class="text-muted d-block mb-2">{{__('Task')}}:</span>
                                                <h6 class="m-0"><a href="{{ route('projects.tasks.index',$due_task->project->id) }}" class="name mb-0 h6">{{ $due_task->name }}</a></h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-muted d-block mb-2">{{__('Project')}}:</span>
                                        <h6 class="m-0">{{$due_task->project->project_name}}</h6>
                                    </td>
                                    <td>

                                        <span class="text-muted d-block mb-2">{{__('Stage')}}:</span>
                                        <div class="d-flex align-items-center h6">
                                            <span class="full-circle bg-{{ \App\Models\ProjectTask::$priority_color[$due_task->priority] }}"></span>
                                            <span class="ms-1">{{ \App\Models\ProjectTask::$priority[$due_task->priority] }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-muted d-block mb-2">{{__('Completion')}}:</span>
                                        <h6 class="m-0">{{ $due_task->taskProgress($due_task)['percentage'] }}</h6>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
