@extends('layouts.admin')
@section('page-title')
    {{ __('Physical Verification') }} — {{ $verification->name }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-verifications.index') }}">{{ __('Physical Verification') }}</a></li>
    <li class="breadcrumb-item">{{ $verification->name }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>{{ $verification->name }}</h5>
                    <span class="badge bg-primary">{{ ucfirst(str_replace('_', ' ', $verification->status)) }}</span>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-2">{{ __('Location') }}</dt>
                        <dd class="col-sm-4">{{ $verification->location ? $verification->location->code.' — '.$verification->location->name : __('Whole company') }}</dd>
                        <dt class="col-sm-2">{{ __('Deadline') }}</dt>
                        <dd class="col-sm-4">{{ $verification->deadline ? $verification->deadline->format('Y-m-d') : '—' }}</dd>
                        <dt class="col-sm-2">{{ __('Started') }}</dt>
                        <dd class="col-sm-4">{{ $verification->started_at ? $verification->started_at->format('Y-m-d H:i') : '—' }}</dd>
                        <dt class="col-sm-2">{{ __('Closed') }}</dt>
                        <dd class="col-sm-4">{{ $verification->closed_at ? $verification->closed_at->format('Y-m-d H:i') : '—' }}</dd>
                    </dl>
                    <p class="mb-0">
                        @foreach ($counts as $result => $count)
                            <span class="badge bg-secondary me-1">{{ ucfirst(str_replace('_', ' ', $result)) }}: {{ $count }}</span>
                        @endforeach
                    </p>
                    <p class="text-muted mt-2 mb-0">{{ __('Missing assets are flagged, not written off. A write-off is a disposal decision.') }}</p>

                    @if ($verification->status === 'planned')
                        <form method="POST" action="{{ route('fixed-asset-verifications.start', $verification->id) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="btn btn-primary">{{ __('Start Campaign') }}</button>
                        </form>
                    @endif
                    @if ($verification->status === 'in_progress')
                        <form method="POST" action="{{ route('fixed-asset-verifications.close', $verification->id) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="btn btn-success">{{ __('Close Campaign') }}</button>
                        </form>
                    @endif
                    @if (in_array($verification->status, ['planned', 'in_progress'], true))
                        <form method="POST" action="{{ route('fixed-asset-verifications.cancel', $verification->id) }}" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger">{{ __('Cancel Campaign') }}</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5>{{ __('Assets') }}</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>{{ __('Asset') }}</th>
                                    <th>{{ __('Expected Location') }}</th>
                                    <th>{{ __('Expected Custodian') }}</th>
                                    <th>{{ __('Result') }}</th>
                                    <th>{{ __('Checked By') }}</th>
                                    <th>{{ __('Note') }}</th>
                                    @if ($verification->status === 'in_progress')
                                        <th>{{ __('Check') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($verification->items as $item)
                                    <tr>
                                        <td>
                                            @if ($item->asset)
                                                <a href="{{ route('fixed-assets.show', $item->asset->id) }}"><strong>{{ $item->asset->code }}</strong></a> — {{ $item->asset->name }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $item->expectedLocation ? $item->expectedLocation->code : '—' }}</td>
                                        <td>{{ $item->expectedCustodian ? $item->expectedCustodian->name : '—' }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $item->result)) }}</td>
                                        <td>{{ $item->checker ? $item->checker->name : '—' }}{{ $item->checked_at ? ' · '.$item->checked_at->format('Y-m-d H:i') : '' }}</td>
                                        <td>{{ $item->note ?? '—' }}</td>
                                        @if ($verification->status === 'in_progress')
                                            <td>
                                                <form method="POST" action="{{ route('fixed-asset-verifications.check', [$verification->id, $item->id]) }}" class="d-flex gap-1">
                                                    @csrf
                                                    <select name="result" class="form-control form-control-sm" required>
                                                        <option value="">{{ __('Select') }}</option>
                                                        @foreach (['found', 'missing', 'damaged', 'wrong_location', 'wrong_custodian'] as $result)
                                                            <option value="{{ $result }}" {{ $item->result === $result ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $result)) }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input type="text" name="note" class="form-control form-control-sm" placeholder="{{ __('Note') }}" maxlength="1000" value="{{ $item->note }}">
                                                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center">{{ __('No assets in this campaign.') }}</td>
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
