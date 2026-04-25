@extends('layouts.admin')
@section('page-title')
    {{__('Manage Journal Entry')}}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Journal Entry')}}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create journal entry')
            <a href="{{ route('journal-entry.create') }}" data-title="{{__('Create New Journal')}}" data-bs-toggle="tooltip"  title="{{__('Create')}}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i>
            </a>
        @endcan
    </div>
@endsection

@section('content')

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th> {{__('Journal ID')}}</th>
                                <th> {{__('Date')}}</th>
                                <th> {{__('Amount')}}</th>
                                <th> {{__('Description')}}</th>
                                <th> {{__('Status')}}</th>
                                <th width="10%"> {{__('Action')}}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($journalEntries as $journalEntry)
                                <tr>
                                    <td class="Id">
                                        <a href="{{ route('journal-entry.show',$journalEntry->id) }}" class="btn btn-outline-primary">{{ AUth::user()->journalNumberFormat($journalEntry->journal_id) }}</a>
                                    </td>
                                    <td>{{ Auth::user()->dateFormat($journalEntry->date) }}</td>
                                    <td>
                                        {{ \Auth::user()->priceFormat($journalEntry->totalCredit())}}
                                    </td>
                                    <td>{{!empty($journalEntry->description)?$journalEntry->description:'-'}}</td>
                                    <td>
                                        @if($journalEntry->status == \App\Models\JournalEntry::STATUS_PENDING)
                                            <span class="badge bg-warning">{{ __('Pending') }}</span>
                                        @elseif($journalEntry->status == \App\Models\JournalEntry::STATUS_APPROVED)
                                            <span class="badge bg-success">{{ __('Approve') }}</span>
                                        @else
                                            <span class="badge bg-danger">{{ __('Reject') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @can('approve journal entry')
                                            @if($journalEntry->status == \App\Models\JournalEntry::STATUS_PENDING)
                                                {!! Form::open(['method' => 'POST', 'route' => ['journal-entry.approve', $journalEntry->id], 'id' => 'approve-form-' . $journalEntry->id, 'class' => 'd-inline']) !!}
                                                <a href="#" class="mx-1 btn btn-sm btn-success bs-pass-para" data-bs-toggle="tooltip" title="{{ __('Approve') }}" data-confirm="{{ __('Approve this journal entry?') }}" data-confirm-yes="document.getElementById('approve-form-{{ $journalEntry->id }}').submit();"><i class="ti ti-check text-white"></i></a>
                                                {!! Form::close() !!}
                                            @endif
                                        @endcan
                                        @can('reject journal entry')
                                            @if($journalEntry->status == \App\Models\JournalEntry::STATUS_PENDING)
                                                {!! Form::open(['method' => 'POST', 'route' => ['journal-entry.reject', $journalEntry->id], 'id' => 'reject-form-' . $journalEntry->id, 'class' => 'd-inline']) !!}
                                                <a href="#" class="mx-1 btn btn-sm btn-danger bs-pass-para" data-bs-toggle="tooltip" title="{{ __('Reject') }}" data-confirm="{{ __('Reject this journal entry?') }}" data-confirm-yes="document.getElementById('reject-form-{{ $journalEntry->id }}').submit();"><i class="ti ti-x text-white"></i></a>
                                                {!! Form::close() !!}
                                            @endif
                                        @endcan
                                        @can('edit journal entry')
                                            <div class="action-btn me-2">
                                                <a data-title="{{__('Edit Journal')}}" href="{{ route('journal-entry.edit',[$journalEntry->id]) }}" class="mx-3 btn btn-sm align-items-center bg-info" data-bs-toggle="tooltip" title="{{__('Edit')}}" data-original-title="{{__('Edit')}}">
                                                    <i class="ti ti-pencil text-white"></i>
                                                </a>
                                            </div>
                                        @endcan
                                        @can('delete journal entry')
                                                <div class="action-btn ">
                                                    {!! Form::open(['method' => 'DELETE', 'route' => array('journal-entry.destroy', $journalEntry->id),'id'=>'delete-form-'.$journalEntry->id]) !!}

                                                    <a href="#" class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger" data-bs-toggle="tooltip" title="{{__('Delete')}}" data-original-title="{{__('Delete')}}" data-confirm="{{__('Are You Sure?').'|'.__('This action can not be undone. Do you want to continue?')}}" data-confirm-yes="document.getElementById('delete-form-{{$journalEntry->id}}').submit();">
                                                            <i class="ti ti-trash text-white"></i>
                                                        </a>
                                                    {!! Form::close() !!}
                                                </div>
                                        @endcan
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
