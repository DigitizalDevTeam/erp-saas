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
        function destroyChoicesOnSelect(el) {
            if (!el) return;
            if (el._choices && typeof el._choices.destroy === 'function') {
                el._choices.destroy();
                el._choices = null;
            }
            if (typeof Choices !== 'undefined' && typeof Choices.getInstance === 'function') {
                try {
                    var inst = Choices.getInstance(el);
                    if (inst) inst.destroy();
                } catch (e) {}
            }
        }

        function loadChartParentOptions(subTypeId) {
            if (!subTypeId || subTypeId === '0' || subTypeId === '') {
                return;
            }
            var el = document.querySelector('#commonModal #parent_account_id') || document.getElementById('parent_account_id');
            if (!el) return;

            $.ajax({
                url: '{{ route('charofAccount.subType') }}',
                type: 'POST',
                cache: false,
                data: {
                    type: subTypeId,
                    _token: '{{ csrf_token() }}',
                    _t: Date.now()
                },
                success: function(data) {
                    destroyChoicesOnSelect(el);
                    var html = '';
                    $.each(data, function(key, value) {
                        html += '<option value="' + key + '">' + $('<div>').text(value).html() + '</option>';
                    });
                    el.innerHTML = html;
                    el.value = '0';
                }
            });
        }

        $(document).on('change', '#commonModal #sub_type, #sub_type', function() {
            $('.acc_check').removeClass('d-none');
            loadChartParentOptions($(this).val());
        });

        $(document).on('click', '#commonModal #account, #account', function() {
            if ($(this).is(':checked')) {
                $('.acc_type').removeClass('d-none');
                var subType = $('#commonModal #sub_type').val() || $('#sub_type').val();
                loadChartParentOptions(subType);
            } else {
                $('.acc_type').addClass('d-none');
                $('#parent_account_id').val('0');
            }
        });

        $(document).on('shown.bs.modal', '#commonModal', function() {
            if ($('#commonModal #parent_account_id').length && $('#commonModal #sub_type').val()) {
                $('.acc_check').removeClass('d-none');
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
@foreach ($accounts as $rootAccount)
    @include('chartOfAccount.partials.account_row', [
        'account' => $rootAccount,
        'filter' => $filter,
        'name_column' => $name_column,
        'depth' => 0,
    ])
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
