@extends('layouts.admin')
@section('page-title')
    {{ __('Reverse Journal Dimensions') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('gl-dimensions.index') }}">{{ __('GL Dimensions') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reverse') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Mirror a journal’s dimensions onto its reversing journal: every dimension row gets a new row with the negated amount on the matching reversal line. The original rows stay readable — the reversal is a new linked row, never an edit in place. The reversal journal itself is posted through the normal journal flow first.') }}</p>

                    <form method="POST" action="{{ route('gl-dimensions.reverse.store') }}">
                        @csrf
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Original journal') }}</label>
                                <select class="form-control" name="original_journal_id" required>
                                    <option value="">{{ __('Select journal') }}</option>
                                    @foreach ($journals as $journal)
                                        <option value="{{ $journal->id }}" {{ (string) old('original_journal_id') === (string) $journal->id ? 'selected' : '' }}>#{{ $journal->journal_id }} — {{ $journal->date }} — {{ $journal->reference }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Reversal journal') }}</label>
                                <select class="form-control" name="reversal_journal_id" required>
                                    <option value="">{{ __('Select journal') }}</option>
                                    @foreach ($journals as $journal)
                                        <option value="{{ $journal->id }}" {{ (string) old('reversal_journal_id') === (string) $journal->id ? 'selected' : '' }}>#{{ $journal->journal_id }} — {{ $journal->date }} — {{ $journal->reference }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Reason') }}</label>
                                <input type="text" class="form-control" name="reason" value="{{ old('reason') }}" required placeholder="{{ __('Why is this journal being reversed?') }}">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Reverse dimensions') }}</button>
                        <a href="{{ route('gl-dimensions.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
