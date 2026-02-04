@extends('layouts.admin')
@section('page-title')
    {{__('Manage Credit Notes')}}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Credit Note')}}</li>
@endsection
@push('script-page')
    <script>
        $(document).on('change', '#bill', function () {

            var id = $(this).val();
            var url = "{{route('bill.get')}}";

            $.ajax({
                url: url,
                type: 'get',
                cache: false,
                data: {
                    'bill_id': id,

                },
                success: function (data) {
                    $('#amount').val(data)
                },

            });

        })
    </script>
@endpush

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('bill.export.notes') }}?type=1" class="btn btn-sm btn-secondary me-2" data-bs-toggle="tooltip" title="{{__('Export')}}">
            <i class="ti ti-file-export"></i>
        </a>
        @can('create debit note')
            <a href="#" data-url="{{ route('bill.custom.credit.notes') }}" data-ajax-popup="true" data-title="{{__('Create New Credit Note')}}" data-bs-toggle="tooltip" title="{{__('Create')}}"  class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i>
            </a>

        @endcan
    </div>
@endsection

@section('content')

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th> {{__('Credit Note')}}</th>
                                <th> {{__('Bill')}}</th>
                                <th> {{__('Vendor')}}</th>
                                <th> {{__('Date')}}</th>
                                <th> {{__('Amount')}}</th>
                                <th> {{__('Description')}}</th>
                                <th width="10%"> {{__('Action')}}</th>
                            </tr>
                            </thead>
                            <tbody>

                            @foreach ($bills as $bill)
                                @if(!empty($bill->creditNote))
                                    @foreach ($bill->creditNote as $creditNote)

                                        <tr class="font-style">
                                            <td class="Id">
                                                <a href="{{ route('bill.note.view',\Crypt::encrypt($creditNote->id)) }}" class="btn btn-outline-primary">{{ AUth::user()->creditNoteNumberFormat($creditNote->uid) }}

                                                </a>
                                            </td>
                                            <td class="Id">
                                                <a href="{{ route('bill.show',\Crypt::encrypt($creditNote->bill)) }}" class="btn btn-outline-primary">{{ AUth::user()->billNumberFormat($bill->bill_id) }}

                                                </a>
                                            </td>
                                            <td>{{ (!empty($bill->vender)?$bill->vender->name:'-') }}</td>
                                            <td>{{ Auth::user()->dateFormat($creditNote->date) }}</td>
                                            <td>{{ Auth::user()->priceFormat($creditNote->amount) }}</td>
                                            <td>{{!empty($creditNote->description)?$creditNote->description:'-'}}</td>
                                            <td class="Action">
                                                <span>
                                                @can('edit debit note')
                                                        <div class="action-btn me-2">
                                                            <a data-url="{{ route('bill.edit.debit.notes',[$creditNote->bill,$creditNote->id]) }}" data-ajax-popup="true" data-title="{{__('Edit Credit Note')}}" href="#" class="mx-3 btn btn-sm align-items-center bg-info" data-bs-toggle="tooltip" title="{{__('Edit')}}" data-original-title="{{__('Edit')}}">
                                                                <i class="ti ti-pencil text-white"></i>
                                                            </a>
                                                        </div>
                                                    @endcan
                                                    @can('edit debit note')
                                                        <div class="action-btn ">
                                                            {!! Form::open(['method' => 'DELETE', 'route' => array('bill.delete.debit.note', $creditNote->bill,$creditNote->id),'id'=>'delete-form-'.$creditNote->id]) !!}

                                                            <a href="#" class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger" data-bs-toggle="tooltip" title="{{__('Delete')}}" data-original-title="{{__('Delete')}}" data-confirm="{{__('Are You Sure?').'|'.__('This action can not be undone. Do you want to continue?')}}" data-confirm-yes="document.getElementById('delete-form-{{$creditNote->id}}').submit();">
                                                                <i class="ti ti-trash text-white"></i>
                                                            </a>
                                                            {!! Form::close() !!}
                                                        </div>
                                                    @endcan
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
