{{ Form::open(['url' => 'customer', 'method' => 'post', 'class' => 'needs-validation', 'novalidate', 'enctype' => 'multipart/form-data']) }}
<div class="modal-body">

    <h5 class="sub-title">{{__('Company Information')}}</h5>
    <div class="row">
        <div class="col-lg-4 col-md-4 col-sm-6">
            <div class="form-group">
                {{Form::label('name',__('Company Name'),array('class'=>'form-label')) }}<x-required></x-required>
                {{Form::text('name',null,array('class'=>'form-control','required'=>'required' ,'placeholder'=>__('Enter Name')))}}
            </div>
        </div>
        <div class="col-lg-4 col-md-4 col-sm-6">
            <div class="form-group">
                <x-mobile label="{{__('Company Phone')}}" name="contact" value="{{old('contact')}}" required placeholder="{{ __('Enter Contact') }}"></x-mobile>
            </div>
        </div>
        <div class="col-lg-4 col-md-4 col-sm-6">
            <div class="form-group">
                {{Form::label('email',__('General Email'),['class'=>'form-label'])}}<x-required></x-required>
                {{Form::email('email',null,array('class'=>'form-control' , 'placeholder'=>__('Enter email'),'required'=>'required'))}}

            </div>
        </div>

        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{Form::label('tax_number',__('Tax Number'),['class'=>'form-label'])}}
                {{Form::text('tax_number',null,array('class'=>'form-control' , 'placeholder' => __('Enter Tax Number')))}}
            </div>
        </div>
        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{ Form::label('price_level_id', __('Price Level'), ['class' => 'form-label']) }}
                <select name="price_level_id" class="form-control select">
                    <option value="">{{ __('Select Price Level') }}</option>
                    @foreach($priceLevels as $level)
                        @php
                            $type = ucfirst($level->type); // Fixed or Percentage
                            $value = $level->type === 'percentage' ? $level->value . '%' : '$' . $level->value;
                        @endphp
                        <option value="{{ $level->id }}">{{ $level->name }} - {{ $type }} - {{ $value }}</option>
                    @endforeach
                </select>
            </div>
        </div>


        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{ Form::label('payment_type', __('Payment Type'), ['class' => 'form-label']) }}
                {{ Form::select('payment_type', ['cash' => 'Cash', 'credit' => 'Credit'], null, ['class' => 'form-control', 'id' => 'payment_type']) }}
            </div>
        </div>
        
        <div class="col-lg-6 col-md-6 col-sm-6" id="credit_terms_group" style="display:none;">
            <div class="form-group">
                {{ Form::label('credit_terms', __('Credit Terms'), ['class' => 'form-label']) }}
                {{ Form::select('credit_terms', ['7 days' => '7 Days', '15 days' => '15 Days', '30 days' => '30 Days', '90 days' => '90 Days', '120 days' => '120 Days'], null, ['class' => 'form-control', 'placeholder' => __('Select Credit Terms')]) }}
            </div>
        </div>
        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{ Form::label('credit_limit', __('Credit Limit (Amount)'), ['class' => 'form-label']) }}
                {{ Form::number('credit_limit', old('credit_limit', 0), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => __('0 = no amount limit')]) }}
                <small class="text-muted">{{ __('If exceeded (amount or overdue days), invoice needs authorization before release.') }}</small>
            </div>
        </div>
        @if(!$customFields->isEmpty())
                    @include('customFields.formBuilder')
        @endif
    </div>

    <h5 class="sub-title">{{__('Billing Addresses')}}</h5>
    <div class="billing_addresses mb-3">
        <div class="row billing_address">
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('billing_name',__('Name'),array('class'=>'','class'=>'form-label')) }}
                    {{Form::text('billing_name[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Name')))}}
                </div>
            </div>
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('billing_phone',__('Phone'),array('class'=>'form-label')) }}
                    {{Form::text('billing_phone[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Phone')))}}
                </div>
            </div>
            <div class="col-md-12">
                <div class="form-group">
                    {{Form::label('billing_address',__('Address'),array('class'=>'form-label')) }}
                    {{Form::textarea('billing_address[]',null,array('class'=>'form-control','rows'=>3 , 'placeholder'=>__('Enter Address')))}}
    
                </div>
            </div>
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('billing_city',__('City'),array('class'=>'form-label')) }}
                    {{Form::text('billing_city[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter City')))}}
    
                </div>
            </div>
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('billing_state',__('State'),array('class'=>'form-label')) }}
                    {{Form::text('billing_state[]',null,array('class'=>'form-control' , 'placeholder' => __('Enter State')))}}
                </div>
            </div>
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('billing_country',__('Country'),array('class'=>'form-label')) }}
                    {{Form::text('billing_country[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Country')))}}
                </div>
            </div>
    
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('billing_zip',__('Zip Code'),array('class'=>'form-label')) }}
                    {{Form::text('billing_zip[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Zip Code')))}}
                </div>
            </div>
        </div>
        
        <button type="button" class="btn btn-primary d-flex p-2 add_billing_address">
            <i class="ti ti-plus"></i>
        </button>

    </div>

    <hr>

    <h5 class="sub-title">{{__('Company KYC Documents')}}</h5>
    <div id="company-kyc-documents">
        <div class="row kyc-document-row mb-2">
            <div class="col-md-4">
                <div class="form-group">
                    <label class="form-label">{{__('KYC Document Type')}}</label>
                    <input type="text" name="kyc[0][document_type]" class="form-control" placeholder="{{ __('e.g. Passport, ID Card') }}">
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label class="form-label">{{__('KYC Document Number')}}</label>
                    <input type="text" name="kyc[0][document_number]" class="form-control" placeholder="{{ __('Document Number') }}">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label class="form-label">{{__('KYC Document Upload')}}</label>
                    <input type="file" name="kyc[0][document]" class="form-control">
                </div>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="button" class="btn btn-danger remove_kyc_document"><i class="ti ti-trash"></i></button>
            </div>
        </div>
    </div>
    <button type="button" class="btn btn-secondary add_kyc_document" data-field="kyc" data-target="#company-kyc-documents">{{__('Add Document')}}</button>

    <hr>

    <h5 class="sub-title">{{__('Contact Person Information')}}</h5>
    <div class="row">
        <div class="col-md-4">
             <div class="form-group">
                <label class="form-label">{{__('Contact Person Name')}}</label>
                <input type="text" name="contacts[0][name]" class="form-control" placeholder="{{ __('Name') }}">
            </div>
        </div>
        <div class="col-md-4">
             <div class="form-group">
                <label class="form-label">{{__('Position')}}</label>
                <input type="text" name="contacts[0][role]" class="form-control" placeholder="{{ __('e.g. Finance Director') }}">
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label class="form-label">{{__('Contact Person Phone')}}</label>
                <input type="text" name="contacts[0][phone]" class="form-control" placeholder="{{ __('Phone') }}">
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
             <div class="form-group">
                <label class="form-label">{{__('Personal Email')}}</label>
                <input type="email" name="contacts[0][email]" class="form-control" placeholder="{{ __('Enter email') }}">
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label class="form-label">{{__('Identification')}}</label>
                <input type="text" name="contacts[0][identification]" class="form-control" placeholder="{{ __('e.g. Passport, ID Card') }}">
            </div>
        </div>
    </div>

    <h5 class="sub-title">{{__('Additional Contacts')}}</h5>
    <button type="button" id="add_contact" class="btn btn-secondary mt-2">{{__('Add More Contact')}}</button>
    

    @include('partials.contact_notification_preferences_customer', ['customer' => null])

    <h5 class="sub-title">{{__('Contact Person KYC Documents')}}</h5>
    <div id="contact-kyc-documents">
        <div class="row kyc-document-row mb-2">
            <div class="col-md-4">
                <div class="form-group">
                    <label class="form-label">{{__('KYC Document Type')}}</label>
                    <input type="text" name="contact_kyc[0][document_type]" class="form-control" placeholder="{{ __('e.g. Passport, ID Card') }}">
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label class="form-label">{{__('KYC Document Number')}}</label>
                    <input type="text" name="contact_kyc[0][document_number]" class="form-control" placeholder="{{ __('Document Number') }}">
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label class="form-label">{{__('KYC Document Upload')}}</label>
                    <input type="file" name="contact_kyc[0][document]" class="form-control">
                </div>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="button" class="btn btn-danger remove_kyc_document"><i class="ti ti-trash"></i></button>
            </div>
        </div>
    </div>
    <button type="button" class="btn btn-secondary add_kyc_document" data-field="contact_kyc" data-target="#contact-kyc-documents">{{__('Add Document')}}</button>

    @if(App\Models\Utility::getValByName('shipping_display')=='on')
        {{-- <div class="col-md-12 text-end">
            <input type="button" id="billing_data" value="{{__('Shipping Same As Billing')}}" class="btn btn-primary">
        </div> --}}
        <h5 class="sub-title mt-3">{{__('Shipping Addresses')}}</h5>
        <div class="shipping_addresses mb-3">
            <div class="row shipping_address">
                <div class="col-lg-6 col-md-6 col-sm-6">
                    <div class="form-group">
                        {{Form::label('shipping_name',__('Name'),array('class'=>'form-label')) }}
                        {{Form::text('shipping_name[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Name')))}}
    
                    </div>
                </div>
                <div class="col-lg-6 col-md-6 col-sm-6">
                    <div class="form-group">
                        {{Form::label('shipping_phone',__('Phone'),array('class'=>'form-label')) }}
                        {{Form::text('shipping_phone[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Phone')))}}
    
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="form-group">
                        {{Form::label('shipping_address',__('Address'),array('class'=>'form-label')) }}
                        <label class="form-label" for="example2cols1Input"></label>
                        {{Form::textarea('shipping_address[]',null,array('class'=>'form-control','rows'=>3 , 'placeholder'=>__('Enter Address')))}}
    
                    </div>
                </div>
    
    
                <div class="col-lg-6 col-md-6 col-sm-6">
                    <div class="form-group">
                        {{Form::label('shipping_city',__('City'),array('class'=>'form-label')) }}
                        {{Form::text('shipping_city[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter City')))}}
    
                    </div>
                </div>
                <div class="col-lg-6 col-md-6 col-sm-6">
                    <div class="form-group">
                        {{Form::label('shipping_state',__('State'),array('class'=>'form-label')) }}
                        {{Form::text('shipping_state[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter State')))}}
    
                    </div>
                </div>
                <div class="col-lg-6 col-md-6 col-sm-6">
                    <div class="form-group">
                        {{Form::label('shipping_country',__('Country'),array('class'=>'form-label')) }}
                        {{Form::text('shipping_country[]',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Country')))}}
    
                    </div>
                </div>
    
    
                <div class="col-lg-6 col-md-6 col-sm-6">
                    <div class="form-group">
                        {{Form::label('shipping_zip',__('Zip Code'),array('class'=>'form-label')) }}
                        {{Form::text('shipping_zip[]',null,array('class'=>'form-control' , 'placeholder' => __('Enter Zip Code')))}}
    
                    </div>
                </div>
    
            </div>
            <button type="button" class="btn btn-primary d-flex p-2 add_shipping_address">
                <i class="ti ti-plus"></i>
            </button>
        </div>
    @endif
    
    <hr>
    
    <h5 class="sub-title">{{__('Bank Account Details')}}</h5>
    <div class="row">
        <div class="col-md-6">
            <label class="form-label">{{__('Bank Name')}}</label>
            <input type="text" name="bank_account[bank_name]" class="form-control" placeholder="{{ __('Bank Name') }}">
        </div>
        <div class="col-md-6">
            <label class="form-label">{{__('Account Number')}}</label>
            <input type="text" name="bank_account[account_number]" class="form-control" placeholder="{{ __('Account Number') }}">
        </div>
        <div class="col-md-6 mt-3">
            <label class="form-label">{{__('Account Holder Name')}}</label>
            <input type="text" name="bank_account[account_holder]" class="form-control" placeholder="{{ __('Account Holder') }}">
        </div>
        <div class="col-md-6 mt-3">
            <label class="form-label">{{__('IFSC / SWIFT Code')}}</label>
            <input type="text" name="bank_account[ifsc]" class="form-control" placeholder="{{ __('IFSC / SWIFT') }}">
        </div>
    </div>
    
    <hr>
    
    <h5 class="sub-title">{{__('Additional Emails for Notifications')}}</h5>
    <div class="row">
        <div class="col-md-12">
            <input type="email" name="additional_emails[]" class="form-control" placeholder="{{ __('Enter additional email') }}">
            <small class="form-text text-muted">{{__('You can add multiple emails separated by commas')}}</small>
        </div>
    </div>

</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Create')}}" class="btn btn-primary">
</div>
{{Form::close()}}
<script>
    // KYC document repeaters (company + contact person). The modal is
    // loaded via AJAX and can be opened repeatedly without a page
    // reload, so handlers are rebound with .off().on() and counters
    // use `var` (a second `let` would throw on re-execution).
    var companyKycIndex = 1;
    var contactKycIndex = 1;

    // Additional contacts: contacts[0] is the contact person, so new
    // rows start at index 1. Same safe pattern as the KYC repeaters
    // (`var` + .off().on()) — the modal is loaded via AJAX and can be
    // opened repeatedly without a page reload.
    var contactIndex = 1;
    $(document).off('click', '#add_contact').on('click', '#add_contact', function() {
        var html = `<div class="row mt-3">
            <div class="col-md-4">
            <div class="form-group">
                <input type="text" name="contacts[${contactIndex}][name]" class="form-control" placeholder="{{ __('Name') }}">
            </div>
            </div>
            <div class="col-md-4">
            <div class="form-group">
                <input type="text" name="contacts[${contactIndex}][role]" class="form-control" placeholder="{{ __('e.g. Finance Director') }}">
            </div>
            </div>
            <div class="col-md-4">
            <div class="form-group">
                <input type="text" name="contacts[${contactIndex}][phone]" class="form-control" placeholder="{{ __('Phone') }}">
            </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-6">
            <div class="form-group">
                <input type="email" name="contacts[${contactIndex}][email]" class="form-control" placeholder="{{ __('Personal Email') }}">
            </div>
            </div>
            <div class="col-md-6">
            <div class="form-group">
                <input type="text" name="contacts[${contactIndex}][identification]" class="form-control" placeholder="{{ __('Identification') }}">
            </div>
            </div>
        </div>`;
        $(this).before(html);
        contactIndex++;
    });

    function kycDocumentRowHtml(field, index) {
        return '<div class="row kyc-document-row mb-2">' +
            '<div class="col-md-4"><div class="form-group">' +
            '<label class="form-label">{{ __('KYC Document Type') }}</label>' +
            '<input type="text" name="' + field + '[' + index + '][document_type]" class="form-control" placeholder="{{ __('e.g. Passport, ID Card') }}">' +
            '</div></div>' +
            '<div class="col-md-4"><div class="form-group">' +
            '<label class="form-label">{{ __('KYC Document Number') }}</label>' +
            '<input type="text" name="' + field + '[' + index + '][document_number]" class="form-control" placeholder="{{ __('Document Number') }}">' +
            '</div></div>' +
            '<div class="col-md-3"><div class="form-group">' +
            '<label class="form-label">{{ __('KYC Document Upload') }}</label>' +
            '<input type="file" name="' + field + '[' + index + '][document]" class="form-control">' +
            '</div></div>' +
            '<div class="col-md-1 d-flex align-items-end"><button type="button" class="btn btn-danger remove_kyc_document"><i class="ti ti-trash"></i></button></div>' +
            '</div>';
    }

    $(document).off('click', '.add_kyc_document').on('click', '.add_kyc_document', function() {
        var field = $(this).data('field');
        var container = $(this).data('target');
        var index = field === 'contact_kyc' ? contactKycIndex++ : companyKycIndex++;
        $(container).append(kycDocumentRowHtml(field, index));
    });

    $(document).off('click', '.remove_kyc_document').on('click', '.remove_kyc_document', function() {
        $(this).closest('.kyc-document-row').remove();
    });
</script>
