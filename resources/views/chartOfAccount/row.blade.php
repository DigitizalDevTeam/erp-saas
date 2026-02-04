@php
    $totalBalance = App\Models\Utility::getAccountBalance($account->id, $filter['startDateRange'], $filter['endDateRange']);
@endphp

<tr>
    <td style="padding-left: {{ $level * 20 }}px; {{ $level == 0 ? 'font-weight: bold;' : '' }}">
        {{ $account->code }}
    </td>
    <td style="{{ $level == 0 ? 'font-weight: bold;' : '' }}">
        <a href="{{ route('report.ledger', $account->id) }}?account={{ $account->id }}">{{ $account->name }}</a>
    </td>
    <td>{{ $account->subType->name ?? '-' }}</td>
    <td>{{ $account->parentAccount->name ?? '-' }}</td>
    <td>
        @if (!empty($totalBalance))
            {{ \Auth::user()->priceFormat($totalBalance) }}
        @else
            -
        @endif
    </td>
    <td>
        @if ($account->is_enabled)
            <span class="badge status_badge bg-primary p-2 px-3 rounded">{{ __('Enabled') }}</span>
        @else
            <span class="badge status_badge bg-danger p-2 px-3 rounded">{{ __('Disabled') }}</span>
        @endif
    </td>
    <td class="Action">
        <div class="action-btn me-2">
            <a href="{{ route('report.ledger', $account->id) }}?account={{ $account->id }}"
                class="mx-3 btn btn-sm align-items-center bg-warning"
                title="{{ __('Transaction Summary') }}">
                <i class="ti ti-wave-sine text-white"></i>
            </a>
        </div>

        @can('edit chart of account')
            <div class="action-btn me-2">
                <a href="#" class="mx-3 btn btn-sm align-items-center bg-info"
                    data-url="{{ route('chart-of-account.edit', $account->id) }}"
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
                    'route' => ['chart-of-account.destroy', $account->id],
                    'id' => 'delete-form-' . $account->id,
                ]) !!}
                <a href="#"
                    class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger"
                    title="{{ __('Delete') }}"
                    data-confirm="{{ __('Are You Sure?') . '|' . __('This action can not be undone. Do you want to continue?') }}"
                    data-confirm-yes="document.getElementById('delete-form-{{ $account->id }}').submit();">
                    <i class="ti ti-trash text-white"></i>
                </a>
                {!! Form::close() !!}
            </div>
        @endcan
    </td>
</tr>

@if ($account->children && $account->children->count())
    @foreach ($account->children as $child)
        @include('chartOfAccount.partials.account_row', [
            'account' => $child,
            'filter' => $filter,
            'level' => $level + 1,
            'class' => 'left'
        ])
    @endforeach
@endif
