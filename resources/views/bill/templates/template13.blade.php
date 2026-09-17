@php
    $paperSize = 'a5';
    $payload = \App\Services\MozambiqueStandardRenderer::build('bill', $bill, [
        'settings' => $settings,
        'img' => $img ?? null,
        'party' => $vendor ?? null,
        'paperSize' => $paperSize,
        'preview' => isset($preview),
    ]);
    extract($payload, EXTR_OVERWRITE);
    $paperSize = 'a5';
    $scriptPartial = 'bill.script';
@endphp
@include('print.mozambique.standard')
