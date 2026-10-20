<div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <strong>{{ __('Price per Period') }}</strong>
        <a class="btn btn-primary btn-sm" href="{{ route('hotel.rate-plans.create') }}">{{ __('Add rate plan') }}</a>
    </div>
    <div class="card-body p-0">
        @if($ratePlans->isEmpty())
            <p class="text-muted small p-3 mb-0">{{ __('No rate plans yet — rooms price from their room type rates.') }}</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Plan') }}</th>
                    <th>{{ __('Room type') }}</th>
                    <th>{{ __('Rate') }}</th>
                    <th>{{ __('Weekend rate') }}</th>
                    <th>{{ __('Monthly rate') }}</th>
                    <th>{{ __('Billing') }}</th>
                    <th>{{ __('Valid') }}</th>
                    <th>{{ __('Priority') }}</th>
                    <th>{{ __('Active') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr></thead>
                <tbody>
                @foreach($ratePlans as $plan)
                    <tr>
                        <td>{{ $plan->name }} <span class="badge bg-{{ $plan->isPromotional() ? 'warning text-dark' : 'secondary' }}">{{ $plan->planTypeLabel() }}</span></td>
                        <td>{{ $plan->roomType?->name ?? __('All') }}</td>
                        <td>{{ number_format((float) $plan->rate, 2) }}</td>
                        <td>{{ $plan->weekend_rate !== null ? number_format((float) $plan->weekend_rate, 2) : '—' }}</td>
                        <td>{{ number_format((float) ($plan->monthly_rate ?? 0), 2) }}</td>
                        <td>{{ $plan->billing_mode ?? 'nightly' }}</td>
                        <td>{{ $plan->valid_from?->format('Y-m-d') ?? '…' }} → {{ $plan->valid_to?->format('Y-m-d') ?? '…' }}</td>
                        <td>{{ $plan->priority }}</td>
                        <td>{{ $plan->is_active ? __('Yes') : __('No') }}</td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-outline-primary btn-sm" href="{{ route('hotel.rate-plans.edit', $plan) }}">{{ __('Edit') }}</a>
                            {!! Form::open(['route' => ['hotel.rate-plans.destroy', $plan], 'method' => 'DELETE', 'class' => 'd-inline', 'onsubmit' => 'return confirm("'.__('Delete this rate plan?').'")']) !!}
                                <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('Delete') }}</button>
                            {!! Form::close() !!}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
