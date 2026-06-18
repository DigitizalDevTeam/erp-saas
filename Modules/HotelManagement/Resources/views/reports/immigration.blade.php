@extends('layouts.admin')
@section('page-title'){{ __('Hotel — Immigration report') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.reports.index') }}">{{ __('Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Immigration') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
    @include('hotelmanagement::reports._nav')
    @include('hotelmanagement::reports._date_filter', ['from' => $from, 'to' => $to])
    <div class="mb-3">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('hotel.reports.immigration.export', request()->query()) }}">{{ __('Export Excel') }}</a>
        <a class="btn btn-sm btn-outline-danger" href="{{ route('hotel.reports.immigration.pdf', request()->query()) }}">{{ __('Export PDF') }}</a>
    </div>
    <p class="text-muted small mb-3">{{ __('Guests staying during the selected period. Includes main guest and group members.') }}</p>
    <div class="table-responsive">
        <table class="table table-striped table-sm">
            <thead>
                <tr>
                    <th>{{ __('Reservation') }}</th>
                    <th>{{ __('Full name') }}</th>
                    <th>{{ __('Nationality') }}</th>
                    <th>{{ __('Country') }}</th>
                    <th>{{ __('ID type') }}</th>
                    <th>{{ __('ID number') }}</th>
                    <th>{{ __('Passport number') }}</th>
                    <th>{{ __('Visa number') }}</th>
                    <th>{{ __('Birth date') }}</th>
                    <th>{{ __('Arrival date') }}</th>
                    <th>{{ __('Departure date') }}</th>
                    <th>{{ __('Room') }}</th>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['reservation_no'] }}</td>
                    <td>{{ $row['full_name'] }}</td>
                    <td>{{ $row['nationality'] }}</td>
                    <td>{{ $row['country'] }}</td>
                    <td>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $row['id_type']))) }}</td>
                    <td>{{ $row['id_number'] }}</td>
                    <td>{{ $row['passport_number'] }}</td>
                    <td>{{ $row['visa_number'] }}</td>
                    <td>{{ $row['birth_date'] }}</td>
                    <td>{{ $row['check_in_date'] }}</td>
                    <td>{{ $row['check_out_date'] }}</td>
                    <td>{{ $row['room'] }}</td>
                    <td>{{ __(\Illuminate\Support\Str::title($row['role'])) }}</td>
                    <td>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $row['status']))) }}</td>
                </tr>
            @empty
                <tr><td colspan="14" class="text-center text-muted py-4">{{ __('No guests found for this period.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-muted small mb-0">{{ __('Total guests') }}: {{ $rows->count() }}</p>
</div></div>
@endsection
