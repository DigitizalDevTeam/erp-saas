@extends('layouts.admin')
@section('page-title')
    {{ __('Settings') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Print-Settings') }}</li>
@endsection

@php
    $logo = \App\Models\Utility::get_file('uploads/logo');
    $company_logo = Utility::getValByName('company_logo');
    $company_favicon = Utility::getValByName('company_favicon');
    $lang = Utility::getValByName('default_language');
    $posLogoFile = $settings['pos_logo'] ?? Utility::getValByName('pos_logo');
    $posLogoUrl = !empty($posLogoFile)
        ? (rtrim((string) Utility::get_file('pos_logo'), '/') . '/' . ltrim((string) $posLogoFile, '/'))
        : '';
    $quotationLogoFile = $settings['quotation_logo'] ?? Utility::getValByName('quotation_logo');
    $quotationLogoUrl = !empty($quotationLogoFile)
        ? (rtrim((string) Utility::get_file('quotation_logo'), '/') . '/' . ltrim((string) $quotationLogoFile, '/'))
        : '';
@endphp
@push('script-page')
    <script>
        var scrollSpy = new bootstrap.ScrollSpy(document.body, {
            target: '#useradd-sidenav',
            offset: 300
        })
    </script>
    <script>
        $(document).on("change", "select[name='purchase_template'], input[name='purchase_color']", function() {
            var template = $("select[name='purchase_template']").val();
            var color = $("input[name='purchase_color']:checked").val();
            $('#purchase_frame').attr('src', '{{ url('/purchase/preview') }}/' + template + '/' + color);
        });

        $(document).on("change", "select[name='quotation_template'], input[name='quotation_color']", function() {
            var template = $("select[name='quotation_template']").val();
            var color = $("input[name='quotation_color']:checked").val();
            $('#quotation_frame').attr('src', '{{ url('/quotation/preview') }}/' + template + '/' + color);
        });

        $(document).on("change", "select[name='pos_template'], input[name='pos_color']", function () {
            var template = $("select[name='pos_template']").val();
            var color = $("input[name='pos_color']:checked").val();
            $('#pos_frame').attr('src', '{{ url('/pos/preview') }}/' + template + '/' + color);
        });

        $(document).on('change', '#pos_logo', function () {
            if (!this.files || !this.files[0]) return;
            $('#pos_image').attr('src', URL.createObjectURL(this.files[0])).show();
        });

        $(document).on('change', '#quotation_logo', function () {
            if (!this.files || !this.files[0]) return;
            $('#quotation_image').attr('src', URL.createObjectURL(this.files[0])).show();
        });

        $(document).on('change', '#purchase_logo', function () {
            if (!this.files || !this.files[0]) return;
            $('#purchase_image').attr('src', URL.createObjectURL(this.files[0])).show();
        });
    </script>
@endpush
@section('content')
    <div class="col-sm-12 mt-4">
        <div class="card">
            <div class="card-body">
                <ul class="nav nav-pills mb-0" id="pills-tab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="pills-purchase-tab" data-bs-toggle="pill" href="#pills-purchase"
                            role="tab" aria-controls="pills-purchase"
                            aria-selected="false">{{ __('Purchase Print Setting') }}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="pills-quotation-tab" data-bs-toggle="pill" href="#pills-quotation"
                            role="tab" aria-controls="pills-pos"
                            aria-selected="false">{{ __('Quotation Print Setting') }}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="pills-pos-tab" data-bs-toggle="pill" href="#pills-pos" role="tab"
                            aria-controls="pills-pos" aria-selected="false">{{ __('POS Print Setting') }}</a>
                    </li>

                </ul>

                <div class="tab-content" id="pills-tabContent">

                    <!--Purchase Setting-->
                    <div class="tab-pane fade  show active" id="pills-purchase" role="tabpanel"
                        aria-labelledby="pills-purchase-tab">

                        <div class="bg-none">
                            <div class="row company-setting">
                                <div class="col-md-3">
                                    <div class="card-body">
                                        
                                        <form id="setting-form" method="post"
                                            action="{{ route('purchase.template.setting') }}" enctype="multipart/form-data">
                                            @csrf
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="form-group">
                                                        <label for="address"
                                                            class="form-label">{{ __('Purchase Template') }}</label>
                                                        <select class="form-control" name="purchase_template">
                                                            @foreach (App\Models\Utility::templateData()['templates'] as $key => $template)
                                                                <option value="{{ $key }}"
                                                                    {{ isset($settings['purchase_template']) && $settings['purchase_template'] == $key ? 'selected' : '' }}>
                                                                    {{ $template }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label for="address"
                                                            class="col-form-label">{{ __('QR Display?') }}</label>
                                                        <div class="d-flex align-items-center">
                                                            <div class="form-check form-switch custom-switch-v1 mt-2">
                                                                <input type="hidden" name="pos_purchase_qr_display"
                                                                    value="off">
                                                                <input type="checkbox"
                                                                    class="form-check-input input-primary"
                                                                    id="customswitchv1-1 pos_purchase_qr_display"
                                                                    name="pos_purchase_qr_display"
                                                                    {{ isset($settings['pos_purchase_qr_display']) && $settings['pos_purchase_qr_display'] == 'on' ? 'checked="checked"' : '' }}>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label class="col-form-label">{{ __('Color Input') }}</label>
                                                <div class="row gutters-xs">
                                                    @foreach (Utility::templateData()['colors'] as $key => $color)
                                                        <div class="col-auto">
                                                            <label class="colorinput">
                                                                <input name="purchase_color" type="radio"
                                                                    value="{{ $color }}" class="colorinput-input"
                                                                    {{ isset($settings['purchase_color']) && $settings['purchase_color'] == $color ? 'checked' : '' }}>
                                                                <span class="colorinput-color"
                                                                    style="background: #{{ $color }}"></span>
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label class="col-form-label">{{ __('Purchase Logo') }}</label>
                                                <div class="choose-files mt-2 ">
                                                    <label for="purchase_logo">
                                                        <div class=" bg-primary purchase_logo_update"> <i
                                                                class="ti ti-upload px-1"></i>{{ __('Choose file here') }}
                                                        </div>
                                                        <input type="file" class="form-control file" name="purchase_logo"
                                                            id="purchase_logo" data-filename="purchase_logo_update">
                                                        <img id="purchase_image" class="mt-2" style="width:25%;" />
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="form-group mt-2 text-end">
                                                <input type="submit" value="{{ __('Save') }}"
                                                    class="btn btn-print-invoice  btn-primary m-r-10">
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    @if (isset($settings['purchase_template']) && isset($settings['purchase_color']))
                                        <iframe id="purchase_frame" class="w-100 h-100" frameborder="0"
                                            src="{{ route('purchase.preview', [$settings['purchase_template'], $settings['purchase_color']]) }}"></iframe>
                                    @else
                                        <iframe id="purchase_frame" class="w-100 h-100" frameborder="0"
                                            src="{{ route('purchase.preview', ['template1', 'fffff']) }}"></iframe>
                                    @endif
                                </div>
                            </div>
                        </div>


                    </div>

                    <!--Quotation Setting-->
                    <div class="tab-pane fade" id="pills-quotation" role="tabpanel"
                        aria-labelledby="pills-quotation-tab">
                        <div class="bg-none">
                            <div class="row company-setting">
                                <div class="col-md-3">
                                    <div class="card-body">
                                        
                                        <form id="setting-form" method="post"
                                            action="{{ route('quotation.template.setting') }}"
                                            enctype="multipart/form-data">
                                            @csrf
                                            <div class="form-group mb-3">
                                                <label for="quotation_receipt_layout" class="form-label fw-semibold">{{ __('Slip Layout') }}</label>
                                                @php
                                                    $qrl = \App\Models\Utility::normalizeReceiptLayout($settings['quotation_receipt_layout'] ?? 'existing');
                                                @endphp
                                                <select class="form-control" name="quotation_receipt_layout" id="quotation_receipt_layout">
                                                    <option value="existing" {{ $qrl === 'existing' ? 'selected' : '' }}>{{ __('Existing Layout') }}</option>
                                                    <option value="compact1" {{ $qrl === 'compact1' ? 'selected' : '' }}>{{ __('Compact Layout 1') }}</option>
                                                    <option value="compact2" {{ $qrl === 'compact2' ? 'selected' : '' }}>{{ __('Compact Layout 2') }}</option>
                                                </select>
                                                <small class="text-muted">{{ __('Existing uses the Quotation Template below. Compact layouts are paper-saving Mozambique-style slips.') }}</small>
                                            </div>
                                            <div class="form-group">
                                                <label for="address"
                                                    class="form-label">{{ __('Quotation Template') }}</label>
                                                <select class="form-control" name="quotation_template">
                                                    @foreach (App\Models\Utility::templateData()['templates'] as $key => $template)
                                                        <option value="{{ $key }}"
                                                            {{ isset($settings['quotation_template']) && $settings['quotation_template'] == $key ? 'selected' : '' }}>
                                                            {{ $template }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label class="col-form-label">{{ __('Color Input') }}</label>
                                                <div class="row gutters-xs">
                                                    @foreach (Utility::templateData()['colors'] as $key => $color)
                                                        <div class="col-auto">
                                                            <label class="colorinput">
                                                                <input name="quotation_color" type="radio"
                                                                    value="{{ $color }}" class="colorinput-input"
                                                                    {{ isset($settings['quotation_color']) && $settings['quotation_color'] == $color ? 'checked' : '' }}>
                                                                <span class="colorinput-color"
                                                                    style="background: #{{ $color }}"></span>
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label class="col-form-label">{{ __('Quotation Logo') }}</label>
                                                <div class="choose-files mt-2 ">
                                                    <label for="quotation_logo">
                                                        <div class=" bg-primary quotation_logo_update"> <i
                                                                class="ti ti-upload px-1"></i>{{ __('Choose file here') }}
                                                        </div>
                                                        <input type="file" class="form-control file"
                                                            name="quotation_logo" id="quotation_logo"
                                                            data-filename="quotation_logo_update">
                                                        <img id="quotation_image" class="mt-2" style="width:25%;max-width:120px;"
                                                            @if(!empty($quotationLogoUrl)) src="{{ $quotationLogoUrl }}" @endif
                                                            alt="{{ __('Quotation Logo') }}"
                                                            onerror="this.style.display='none'">
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="form-group mt-2 text-end">
                                                <input type="submit" value="{{ __('Save') }}"
                                                    class="btn btn-print-invoice  btn-primary m-r-10">
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    @if (isset($settings['quotation_template']) && isset($settings['quotation_color']))
                                        <iframe id="quotation_frame" class="w-100 h-100" frameborder="0"
                                            src="{{ route('quotation.preview', [$settings['quotation_template'], $settings['quotation_color']]) }}"></iframe>
                                    @else
                                        <iframe id="quotation_frame" class="w-100 h-100" frameborder="0"
                                            src="{{ route('quotation.preview', ['template1', 'fffff']) }}"></iframe>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <!--POS Setting-->
                    <div class="tab-pane fade" id="pills-pos" role="tabpanel" aria-labelledby="pills-pos-tab">
                        <div class="bg-none">
                            <div class="row company-setting">
                                <div class="col-md-3">
                                    <div class="card-body">
                                        
                                        <form id="setting-form" method="post"
                                            action="{{ route('pos.template.setting') }}" enctype="multipart/form-data">
                                            @csrf
                                            <hr class="my-3">
                                            <h6 class="fw-semibold mb-2">{{ __('Receipt printer & paper') }}</h6>
                                            <p class="small text-muted mb-3">{{ __('Choose the paper size that matches your physical receipt printer. The receipt HTML uses fixed page sizes (A4 / A5 / 80mm) so you only set your OS or Chrome default printer once to the same paper—customers should not need to change margins or scale each time.') }}</p>
                                            <div class="form-group mb-3">
                                                <label for="pos_receipt_layout" class="form-label fw-semibold">{{ __('Slip Layout') }}</label>
                                                @php
                                                    $prl = \App\Models\Utility::normalizeReceiptLayout($settings['pos_receipt_layout'] ?? 'existing');
                                                @endphp
                                                <select class="form-control" name="pos_receipt_layout" id="pos_receipt_layout">
                                                    <option value="existing" {{ $prl === 'existing' ? 'selected' : '' }}>{{ __('Existing Layout') }}</option>
                                                    <option value="compact1" {{ $prl === 'compact1' ? 'selected' : '' }}>{{ __('Compact Layout 1') }}</option>
                                                    <option value="compact2" {{ $prl === 'compact2' ? 'selected' : '' }}>{{ __('Compact Layout 2') }}</option>
                                                    <option value="compact3" {{ $prl === 'compact3' ? 'selected' : '' }}>{{ __('POS Layout 3') }}</option>
                                                </select>
                                                <small class="text-muted">{{ __('Existing keeps the current receipt. Compact layouts save paper (thermal / dense Mozambique style). POS Layout 3 is the UTHARA 80mm talão.') }}</small>
                                            </div>
                                            <div class="form-group mb-3">
                                                <label for="pos_print_layout" class="form-label fw-semibold">{{ __('Receipt paper / layout') }}</label>
                                                <select class="form-control" name="pos_print_layout" id="pos_print_layout">
                                                    @php
                                                        $pl = \App\Models\Utility::normalizePosPrintLayout($settings['pos_print_layout'] ?? 'slip');
                                                    @endphp
                                                    <option value="slip" {{ $pl === 'slip' ? 'selected' : '' }}>{{ __('Thermal roll 80 mm (UTHARA Talão)') }}</option>
                                                    <option value="a5" {{ $pl === 'a5' ? 'selected' : '' }}>{{ __('A5 Factura (148 × 210 mm)') }}</option>
                                                    <option value="a4" {{ $pl === 'a4' ? 'selected' : '' }}>{{ __('A4 Factura (210 × 297 mm)') }}</option>
                                                </select>
                                                <small class="text-muted">{{ __('Thermal: narrow UTHARA ticket. A5/A4: Mozambique factura page. Preview matches paper size before print.') }}</small>
                                            </div>
                                            <div class="form-group mb-3">
                                                <label for="uthara_factura_paper" class="form-label fw-semibold">{{ __('UTHARA Invoice / Quotation paper') }}</label>
                                                <select class="form-control" name="uthara_factura_paper" id="uthara_factura_paper">
                                                    @php
                                                        $ufp = strtolower((string) ($settings['uthara_factura_paper'] ?? 'a4'));
                                                        if (! in_array($ufp, ['a4', 'a5'], true)) { $ufp = 'a4'; }
                                                    @endphp
                                                    <option value="a4" {{ $ufp === 'a4' ? 'selected' : '' }}>{{ __('A4') }}</option>
                                                    <option value="a5" {{ $ufp === 'a5' ? 'selected' : '' }}>{{ __('A5') }}</option>
                                                </select>
                                                <small class="text-muted">{{ __('Used for Invoice, Quotation and Bill formal prints (UTHARA Mozambique layout).') }}</small>
                                            </div>
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-semibold">{{ __('UTHARA print pack') }}</label>
                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="uthara_print_pack" value="off">
                                                    <input class="form-check-input" type="checkbox" name="uthara_print_pack" id="uthara_print_pack" value="on" {{ (($settings['uthara_print_pack'] ?? 'on') !== 'off') ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="uthara_print_pack">{{ __('Use UTHARA Factura / Talão layouts (recommended)') }}</label>
                                                </div>
                                            </div>
                                            <div class="form-group mb-3">
                                                <label for="pos_receipt_printer" class="form-label fw-semibold">{{ __('Print target') }}</label>
                                                <select class="form-control" name="pos_receipt_printer" id="pos_receipt_printer">
                                                    <option value="browser" {{ (isset($settings['pos_receipt_printer']) && $settings['pos_receipt_printer'] == 'browser') || !isset($settings['pos_receipt_printer']) ? 'selected' : '' }}>{{ __('Browser dialog (pick printer each time)') }}</option>
                                                    <option value="default" {{ (isset($settings['pos_receipt_printer']) && $settings['pos_receipt_printer'] == 'default') ? 'selected' : '' }}>{{ __('Use system default printer') }}</option>
                                                </select>
                                                <small class="text-muted">{{ __('For checkout PCs: set Windows/macOS default printer to your receipt printer and paper size once. Prefer “System default” together with Direct print below.') }}</small>
                                            </div>
                                            <div class="form-group mb-3">
                                                <label class="form-label fw-semibold">{{ __('Direct print (no dialog)') }}</label>
                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="pos_direct_print" value="off">
                                                    <input class="form-check-input" type="checkbox" name="pos_direct_print" id="pos_direct_print" value="on" {{ (isset($settings['pos_direct_print']) && $settings['pos_direct_print'] == 'on') ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="pos_direct_print">{{ __('Send to default printer without opening the print dialog') }}</label>
                                                </div>
                                                <small class="text-muted">{{ __('Launch Chrome (or your POS browser) with --kiosk-printing and set the machine default printer and paper to match “Receipt paper / layout” above.') }}</small>
                                            </div>
                                            <hr class="my-3">
                                            <div class="form-group">
                                                <label for="address" class="form-label">{{ __('POS Template') }}</label>
                                                <select class="form-control" name="pos_template">
                                                    @foreach (App\Models\Utility::templateData()['templates'] as $key => $template)
                                                        <option value="{{ $key }}"
                                                            {{ isset($settings['pos_template']) && $settings['pos_template'] == $key ? 'selected' : '' }}>
                                                            {{ $template }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="col-form-label">{{ __('Color Input') }}</label>
                                                <div class="row gutters-xs">
                                                    @foreach (Utility::templateData()['colors'] as $key => $color)
                                                        <div class="col-auto">
                                                            <label class="colorinput">
                                                                <input name="pos_color" type="radio"
                                                                    value="{{ $color }}" class="colorinput-input"
                                                                    {{ isset($settings['pos_color']) && $settings['pos_color'] == $color ? 'checked' : '' }}>
                                                                <span class="colorinput-color"
                                                                    style="background: #{{ $color }}"></span>
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="pos_qr_display" class="col-form-label">{{ __('QR Display?') }}</label>
                                                <div class="d-flex align-items-center">
                                                    <div class="form-check form-switch custom-switch-v1 mt-2">
                                                        <input type="hidden" name="pos_qr_display" value="off">
                                                        <input type="checkbox" class="form-check-input input-primary"
                                                            id="pos_qr_display" name="pos_qr_display" value="on"
                                                            {{ ($settings['pos_qr_display'] ?? 'on') === 'on' ? 'checked' : '' }}>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label class="col-form-label">{{ __('POS Logo') }}</label>
                                                <div class="choose-files mt-2 ">
                                                    <label for="pos_logo">
                                                        <div class=" bg-primary pos_logo_update"> <i
                                                                class="ti ti-upload px-1"></i>{{ __('Choose file here') }}
                                                        </div>
                                                        <input type="file" class="form-control file" name="pos_logo"
                                                            id="pos_logo" data-filename="pos_logo_update">
                                                        <img id="pos_image" class="mt-2" style="width:25%;max-width:120px;"
                                                            @if(!empty($posLogoUrl)) src="{{ $posLogoUrl }}" @endif
                                                            alt="{{ __('POS Logo') }}"
                                                            onerror="this.style.display='none'">
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="form-group mt-2 text-end">
                                                <input type="submit" value="{{ __('Save') }}"
                                                    class="btn btn-print-invoice  btn-primary m-r-10">
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    @if (isset($settings['pos_template']) && isset($settings['pos_color']))
                                        <iframe id="pos_frame" class="w-100 h-100" frameborder="0"
                                            src="{{ route('pos.preview', [$settings['pos_template'], $settings['pos_color']]) }}"></iframe>
                                    @else
                                        <iframe id="pos_frame" class="w-100 h-100" frameborder="0"
                                            src="{{ route('pos.preview', ['template1', 'fffff']) }}"></iframe>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    </div>
@endsection
