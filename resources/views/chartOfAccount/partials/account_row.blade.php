@php
    $depth = $depth ?? 0;
    $padding = 20 + ($depth * 24);
    $isRoot = $depth === 0;
    $displayName = $account->{$name_column} ?? $account->labelForLocale();
    $mainType = $account->types?->{$name_column} ?? $account->types?->name ?? '';
    $subType = $account->subType?->{$name_column} ?? $account->subType?->name ?? '';
    $typeLabel = $mainType !== '' && $subType !== '' && $mainType !== $subType
        ? $mainType . ' / ' . $subType
        : ($mainType ?: ($subType ?: '-'));
    $balance = App\Models\Utility::getAccountBalance($account->id, $filter['startDateRange'], $filter['endDateRange']);
    $children = $account->tree_children ?? collect();
@endphp
<tr @if($isRoot) style="font-weight:bold; background:#f8f9fa;" @endif>
    <td style="padding-left: {{ $padding }}px;">{{ $account->code }}</td>
    <td style="padding-left: {{ $padding }}px;">
        @if($isRoot)
            {{ $displayName }}
        @else
            <a href="{{ route('report.ledger', $account->id) }}?account={{ $account->id }}">{{ $displayName }}</a>
        @endif
    </td>
    <td>{{ $typeLabel }}</td>
    <td>
        @if (!empty($balance))
            {{ \Auth::user()->priceFormat($balance) }}
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
        @unless($isRoot)
            <div class="action-btn me-2">
                <a href="{{ route('report.ledger', $account->id) }}?account={{ $account->id }}"
                    class="mx-3 btn btn-sm align-items-center bg-warning"
                    title="{{ __('Transaction Summary') }}">
                    <i class="ti ti-wave-sine text-white"></i>
                </a>
            </div>
        @endunless
        @if($account->is_default != 1)
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
        @else
            @if($isRoot)
                -
            @endif
        @endif
    </td>
</tr>
@foreach ($children as $child)
    @include('chartOfAccount.partials.account_row', [
        'account' => $child,
        'filter' => $filter,
        'name_column' => $name_column,
        'depth' => $depth + 1,
    ])
@endforeach
