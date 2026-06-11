@php
    $checkInVal = $checkIn ?? now()->addDay()->toDateString();
    $checkOutVal = $checkOut ?? now()->addDays(2)->toDateString();
    $adultsVal = (int) ($adults ?? 2);
    $childrenVal = (int) ($children ?? 0);
    $submitLabel = $submitLabel ?? __('Search availability');
    $compact = $compact ?? false;
@endphp
<form method="get" action="{{ route('hotel.public.search', ['hotelSlug' => $site->slug]) }}" class="hb-search-widget {{ $compact ? 'hb-search-widget--compact' : '' }}">
    <div class="hb-search-grid">
        <div class="hb-field">
            <label>{{ __('Check-in') }}</label>
            <input type="date" name="check_in" value="{{ $checkInVal }}" min="{{ now()->toDateString() }}" required>
        </div>
        <div class="hb-field">
            <label>{{ __('Check-out') }}</label>
            <input type="date" name="check_out" value="{{ $checkOutVal }}" min="{{ now()->addDay()->toDateString() }}" required>
        </div>
        <div class="hb-field">
            <label>{{ __('Adults') }}</label>
            <select name="adults">
                @for($i = 1; $i <= 6; $i++)
                    <option value="{{ $i }}" @selected($adultsVal === $i)>{{ $i }}</option>
                @endfor
            </select>
        </div>
        <div class="hb-field">
            <label>{{ __('Children') }}</label>
            <select name="children">
                @for($i = 0; $i <= 4; $i++)
                    <option value="{{ $i }}" @selected($childrenVal === $i)>{{ $i }}</option>
                @endfor
            </select>
        </div>
        <div class="hb-field hb-search-submit">
            <label class="hb-sr-only">{{ $submitLabel }}</label>
            <button type="submit" class="hb-btn hb-btn-primary hb-btn-block">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                {{ $submitLabel }}
            </button>
        </div>
    </div>
</form>
