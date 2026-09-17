@php
    $paperSize = 'a4';
    $payload = \App\Services\MozambiqueStandardRenderer::build('invoice', $invoice, [
        'settings' => $settings,
        'img' => $img ?? null,
        'party' => $customer ?? null,
        'paperSize' => $paperSize,
        'preview' => isset($preview),
    ]);
    extract($payload, EXTR_OVERWRITE);
    $paperSize = 'a4';
    $scriptPartial = 'invoice.script';
@endphp
@include('print.mozambique.standard')
