@extends('layouts.admin')
@section('page-title')
    {{ __('Assign Tables') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.show', $pos_shift) }}">{{ __('Shift') }}</a></li>
    <li class="breadcrumb-item">{{ __('Assign Tables') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <p><strong>{{ __('Branch') }}:</strong> {{ $pos_shift->branch ? $pos_shift->branch->name : '-' }}</p>
                    <p><strong>{{ __('Waiter') }}:</strong> {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</p>
                    <hr>
                    {{ Form::open(['route' => ['pos-shift.assign-table.store', $pos_shift], 'method' => 'post']) }}
                    <div class="form-group">
                        <label class="form-label">{{ __('Select tables to assign to this waiter') }}</label>
                        <div class="row mt-2">
                            @foreach($tables as $table)
                                <div class="col-md-4 col-lg-3 mb-2">
                                    <div class="form-check">
                                        {{ Form::checkbox('table_ids[]', $table->id, in_array($table->id, $assigned), ['class' => 'form-check-input', 'id' => 'table_' . $table->id]) }}
                                        <label class="form-check-label" for="table_{{ $table->id }}">
                                            {{ $table->name }} ({{ $table->area ? $table->area->name : '-' }})
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if($tables->isEmpty())
                            <p class="text-muted">{{ __('No tables in this branch. Add areas and tables first.') }}</p>
                        @endif
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">{{ __('Save Assignments') }}</button>
                        <a href="{{ route('pos-shift.show', $pos_shift) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
