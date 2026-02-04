@extends('layouts.admin')
@section('page-title')
    {{__('Dashboard')}}
@endsection
@push('script-page')
    <script>

    </script>
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('CRM')}}</li>
@endsection
@section('content')
<style>
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
<div class="row my-2 gy-3">
    <div class="col-xl-4 col-sm-6 col-12 ">
        <div class="modern-card card card-body">
            <div class="card-top">
                <div class="icon-box bg-customers">
                    <i class="ti ti-user-plus"></i>
                </div>
                <div class="stat-number">{{ $crm_data['total_leads'] }}</div>
            </div>
            <div class="card-title">
                <a href="{{ route('leads.index') }}">{{ __('Total Lead') }}</a>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-sm-6 col-12">
        <div class="modern-card card card-body">
            <div class="card-top">
                <div class="icon-box bg-customers">
                    <i class="ti ti-briefcase"></i>
                </div>
                <div class="stat-number">{{ $crm_data['total_deals'] }}</div>
            </div>
            <div class="card-title">
                <a href="{{ route('deals.index') }}">{{ __('Total Deal') }}</a>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-sm-6 col-12">
        
        <div class="modern-card card card-body">
            <div class="card-top">
                <div class="icon-box bg-customers">
                    <i class="ti ti-file-text"></i>
                </div>
                <div class="stat-number">{{ $crm_data['total_contracts'] }}</div>
            </div>
            <div class="card-title">
                <a href="{{ route('contract.index') }}">{{ __('Total Contract') }}</a>
            </div>
        </div>
    </div>
</div>
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card h-100 mb-0">
                <div class="card-header">
                    <h5>{{__('Lead Status')}}</h5>
                </div>
                <div class="card-body">
                    <div class="row ">
                        @foreach($crm_data['lead_status'] as $status => $val)
                            <div class="col-md-6 col-sm-6 mb-4">
                                <div class="align-items-start">
                                    <div class="ms-2">
                                        <p class="text-sm mb-2">{{$val['lead_stage']}}</p>
                                        <h3 class="mb-2 text-primary">{{ $val['lead_percentage'] }}%</h3>
                                        <div class="progress mb-0">
                                            <div class="progress-bar bg-primary" style="width:{{$val['lead_percentage']}}%;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card h-100 mb-0">
                <div class="card-header">
                    <h5>{{__('Deal Status')}}</h5>
                </div>
                <div class="card-body">
                    <div class="row ">
                        @foreach($crm_data['deal_status'] as $status => $val)
                            <div class="col-md-6 col-sm-6 mb-4">
                                <div class="align-items-start">
                                    <div class="ms-2">
                                        <p class="text-sm mb-2">{{$val['deal_stage']}}</p>
                                        <h3 class="mb-2 text-primary">{{ $val['deal_percentage'] }}%</h3>
                                        <div class="progress mb-0">
                                            <div class="progress-bar bg-primary" style="width:{{$val['deal_percentage']}}%;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xxl-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mt-1 mb-0">{{__('Latest Contract')}}</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th></th>
                                <th>{{__('Subject')}}</th>
                                @if(\Auth::user()->type!='client')
                                <th>{{__('Client')}}</th>
                                @endif
                                <th>{{__('Project')}}</th>
                                <th>{{__('Contract Type')}}</th>
                                <th>{{__('Contract Value')}}</th>
                                <th>{{__('Start Date')}}</th>
                                <th>{{__('End Date')}}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($crm_data['latestContract'] as $contract)
                                <tr>
                                    <td>
                                        <a href="{{route('contract.show',$contract->id)}}" class="btn btn-outline-primary">{{\Auth::user()->contractNumberFormat($contract->id)}}</a>
                                    </td>
                                    <td>{{ $contract->subject}}</td>
                                    @if(\Auth::user()->type!='client')
                                        <td>{{ !empty($contract->clients)?$contract->clients->name:'-' }}</td>
                                    @endif
                                    <td>{{ !empty($contract->projects)?$contract->projects->project_name:'-' }}</td>
                                    <td>{{ !empty($contract->types)?$contract->types->name:'' }}</td>
                                    <td>{{ \Auth::user()->priceFormat($contract->value) }}</td>
                                    <td>{{ \Auth::user()->dateFormat($contract->start_date )}}</td>
                                    <td>{{ \Auth::user()->dateFormat($contract->end_date )}}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8">
                                        <div class="text-center">
                                            <h6>{{__('There is no latest contract')}}</h6>
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
    </div>
@endsection
