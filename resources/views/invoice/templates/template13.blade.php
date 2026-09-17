@php
    $paperSize = 'a5';
    $payload = \App\Services\MozambiqueStandardRenderer::build('invoice', $invoice, [
        'settings' => $settings,
        'img' => $img ?? null,
        'party' => $customer ?? null,
        'paperSize' => $paperSize,
        'preview' => isset($preview),
    ]);
    extract($payload, EXTR_OVERWRITE);
    $paperSize = 'a5';
    $scriptPartial = 'invoice.script';
@endphp
@include('print.mozambique.standard')
