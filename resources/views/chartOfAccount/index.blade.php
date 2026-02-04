@extends('layouts.admin')
@section('page-title')
    {{ __('Manage Chart of Accounts') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Chart of Account') }}</li>
@endsection
@push('script-page')
    <script>
        $(document).on('change', '#sub_type', function() {
            $('.acc_check').removeClass('d-none');
            var type = $(this).val();
            $.ajax({
                url: '{{ route('charofAccount.subType') }}',
                type: 'POST',
                data: {
                    "type": type,
                    "_token": "{{ csrf_token() }}",
                },
                success: function(data) {
                    $('#parent').empty();
                    $.each(data, function(key, value) {
                        $('#parent').append('<option value="' + key + '">' + value +
                            '</option>');
                    });
                }
            });
        });
        $(document).on('click', '#account', function() {
            const element = $('#account').is(':checked');
            $('.acc_type').addClass('d-none');
            if (element==true) {
                $('.acc_type').removeClass('d-none');
            } else {
                $('.acc_type').addClass('d-none');
            }
        });
    </script>
            <script>
                $(document).ready(function () {
                    callback();
                    function callback() {
                        var start_date = $(".startDate").val();
                        var end_date = $(".endDate").val();

                        $('.start_date').val(start_date);
                        $('.end_date').val(end_date);

                    }
                    });

            </script>
@endpush

@section('action-btn')
    <div class="float-end">
        @can('create chart of account')
            <a href="#" data-url="{{ route('chart-of-account.create') }}" data-bs-toggle="tooltip" title="{{ __('Create') }}"
                data-size="lg" data-ajax-popup="true" data-title="{{ __('Create New Account') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i>
            </a>
        @endcan
    </div>
@endsection
@section('content')
<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="mt-2" id="multiCollapseExample1">
            <div class="card" id="show_filter">
                <div class="card-body">
                    {{ Form::open(['route' => ['chart-of-account.index'], 'method' => 'GET', 'id' => 'report_bill_summary']) }}
                    <div class="row align-items-center justify-content-end">
                        <div class="col-xl-10">
                            <div class="row">
                                <div class="col-xl-6 col-lg-6 col-md-6 col-sm-12 col-12">
                                    <div class="btn-box">
                                        {{ Form::label('search', __('Search By name or code'), ['class' => 'form-label']) }}
                                        {{ Form::text('search', $filter['search'], ['class' => 'search form-control']) }}
                                    </div>
                                </div>
                                <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                    <div class="btn-box">
                                        {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                                        {{ Form::date('start_date', $filter['startDateRange'], ['class' => 'startDate form-control']) }}
                                    </div>
                                </div>

                                <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                    <div class="btn-box">
                                        {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                                        {{ Form::date('end_date', $filter['endDateRange'], ['class' => 'endDate form-control']) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto mt-4">
                            <div class="row">
                                <div class="col-auto">
                                    <a href="#" class="btn btn-sm btn-primary me-1"
                                        onclick="document.getElementById('report_bill_summary').submit(); return false;"
                                        data-bs-toggle="tooltip" title="{{ __('Apply') }}"
                                        data-original-title="{{ __('apply') }}">
                                        <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                    </a>

                                    <a href="{{ route('chart-of-account.index') }}" class="btn btn-sm btn-danger "
                                        data-bs-toggle="tooltip" title="{{ __('Reset') }}"
                                        data-original-title="{{ __('Reset') }}">
                                        <span class="btn-inner--icon"><i
                                                class="ti ti-refresh text-white-off "></i></span>
                                    </a>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
                {{ Form::close() }}
            </div>
        </div>
    </div>
</div>
    <div class="row">
        @foreach ($chartAccounts as $type => $accounts)
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h6>{{ $type }}</h6>
                    </div>
                    <div class="card-body table-border-style">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th width="10%"> {{ __('Code') }}</th>
                                        <th width="30%"> {{ __('Name') }}</th>
                                        <th width="20%"> {{ __('Type') }}</th>
                                        <!--<th width="20%"> {{ __('Parent Account Name') }}</th>-->
                                        <th width="20%"> {{ __('Balance') }}</th>
                                        <th width="10%"> {{ __('Status') }}</th>
                                        <th width="10%"> {{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
@foreach ($accounts as $parent)
    @php
        $parentBalance = App\Models\Utility::getAccountBalance($parent->id, $filter['startDateRange'], $filter['endDateRange']);
    @endphp
    {{-- 🔹 Parent Row --}}
    <tr style="font-weight:bold; background:#f8f9fa;">
        <td>{{ $parent->accountMain ? $parent->accountMain->code : '-' }}</td>
        <td>{{ $parent->$name_column }}</td>
        <td>{{ $parent->subType->$name_column ?? '-' }}</td>
        <!--<td>-</td>-->
        <td>
            @if (!empty($parentBalance))
                {{ \Auth::user()->priceFormat($parentBalance) }}
            @else
                -
            @endif
        </td>
        <td>
            @if($parent->accountMain)
            @if ($parent->accountMain->is_enabled)
                <span class="badge status_badge bg-primary p-2 px-3 rounded">{{ __('Enabled') }}</span>
            @else
                <span class="badge status_badge bg-danger p-2 px-3 rounded">{{ __('Disabled') }}</span>
            @endif
            @endif
        </td>
        <td>-</td>
    </tr>
    
    {{-- 🔸 Child Accounts --}}

@foreach ($parent->children as $child)
        @php
            $totalBalance = App\Models\Utility::getAccountBalance($child->id, $filter['startDateRange'], $filter['endDateRange']);
        @endphp
        <tr>
            <td style="padding-left: 65px;">{{ $child->code }}</td>
            <td style="padding-left: 65px;">
                <a href="{{ route('report.ledger', $child->id) }}?account={{ $child->id }}">{{ $child->$name_column }}</a>
            </td>
            <td>{{ $child->subType->$name_column ?? '-' }}</td>
            <!--<td>{{ $parent->name }}</td>-->
            <td>
                @if (!empty($totalBalance))
                    {{ \Auth::user()->priceFormat($totalBalance) }}
                @else
                    -
                @endif
            </td>
            <td>
                @if ($child->is_enabled)
                    <span class="badge status_badge bg-primary p-2 px-3 rounded">{{ __('Enabled') }}</span>
                @else
                    <span class="badge status_badge bg-danger p-2 px-3 rounded">{{ __('Disabled') }}</span>
                @endif
            </td>
            <td class="Action">
                <div class="action-btn me-2">
                    <a href="{{ route('report.ledger', $child->id) }}?account={{ $child->id }}"
                        class="mx-3 btn btn-sm align-items-center bg-warning"
                        title="{{ __('Transaction Summary') }}">
                        <i class="ti ti-wave-sine text-white"></i>
                    </a>
                </div>

                @can('edit chart of account')
                    <div class="action-btn me-2">
                        <a href="#" class="mx-3 btn btn-sm align-items-center bg-info"
                            data-url="{{ route('chart-of-account.edit', $child->id) }}"
                            data-ajax-popup="true"
                            data-title="{{ __('Edit Account') }}"
                            title="{{ __('Edit') }}">
                            <i class="ti ti-pencil text-white"></i>
                        </a>
                    </div>
                @endcan

                @can('delete chart of account')
                    <div class="action-btn ">
                        {!! Form::open([
                            'method' => 'DELETE',
                            'route' => ['chart-of-account.destroy', $child->id],
                            'id' => 'delete-form-' . $child->id,
                        ]) !!}
                        <a href="#"
                            class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger"
                            title="{{ __('Delete') }}"
                            data-confirm="{{ __('Are You Sure?') . '|' . __('This action can not be undone. Do you want to continue?') }}"
                            data-confirm-yes="document.getElementById('delete-form-{{ $child->id }}').submit();">
                            <i class="ti ti-trash text-white"></i>
                        </a>
                        {!! Form::close() !!}
                    </div>
                @endcan
            </td>
        </tr>
    @endforeach
@endforeach
</tbody>

                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
