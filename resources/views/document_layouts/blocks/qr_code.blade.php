<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
    @if(!empty($payload['qr_html']))
        {!! $payload['qr_html'] !!}
    @else
        <span class="dl-muted">{{ __('QR') }}</span>
    @endif
</div>
