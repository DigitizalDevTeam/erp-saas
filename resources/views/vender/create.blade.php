<!--{{Form::open(array('url'=>'vender','method'=>'post', 'class'=>'needs-validation', 'novalidate'))}}-->
{{ Form::open([
    'url' => 'vender',
    'method' => 'post',
    'class' => 'needs-validation',
    'novalidate',
    'enctype' => 'multipart/form-data'
]) }}

<div class="modal-body">

    <h5 class="sub-title mb-3">{{__('Basic Info')}}</h5>
    <div class="row">
        <div class="col-lg-4 col-md-4 col-sm-6">
            <div class="form-group">
                {{Form::label('name',__('Name'),array('class'=>'form-label')) }}<x-required></x-required>
                {{Form::text('name',null,array('class'=>'form-control','required'=>'required' , 'placeholder'=>__('Enter Name')))}}

            </div>
        </div>
        <div class="col-lg-4 col-md-4 col-sm-6">
            <div class="form-group">
                <x-mobile label="{{__('Contact')}}" name="contact" value="{{old('contact')}}" required placeholder="Enter Contact"></x-mobile>

            </div>
        </div>
        <div class="col-lg-4 col-md-4 col-sm-6">
            <div class="form-group">
                {{Form::label('email',__('Email'),['class'=>'form-label'])}}<x-required></x-required>
                {{Form::email('email',null,array('class'=>'form-control','required'=>'required' , 'placeholder' => __('Enter email')))}}
            </div>
        </div>


        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{Form::label('tax_number',__('Tax Number'),['class'=>'form-label'])}}
                {{Form::text('tax_number',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Tax Number')))}}
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

        @if(!$customFields->isEmpty())
                    @include('customFields.formBuilder')
        @endif
    </div>
    <h5 class="sub-title mb-3">{{__('Billing Address')}}</h5>
    <div class="row">
        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{Form::label('billing_name',__('Name'),array('class'=>'form-label')) }}
                {{Form::text('billing_name',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Name')))}}

            </div>
        </div>
        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{Form::label('billing_phone',__('Phone'),array('class'=>'form-label')) }}
                {{Form::text('billing_phone',null,array('class'=>'form-control' , 'placeholder' => __('Enter Phone')))}}

            </div>
        </div>
        <div class="col-md-12">
            <div class="form-group">
                {{Form::label('billing_address',__('Address'),array('class'=>'form-label')) }}
                {{Form::textarea('billing_address',null,array('class'=>'form-control','rows'=>3 , 'placeholder' => __('Enter Address')))}}
            </div>
        </div>

        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{Form::label('billing_city',__('City'),array('class'=>'form-label')) }}
                {{Form::text('billing_city',null,array('class'=>'form-control' , 'placeholder' => __('Enter City')))}}
            </div>
        </div>

        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{Form::label('billing_state',__('State'),array('class'=>'form-label')) }}
                {{Form::text('billing_state',null,array('class'=>'form-control' , 'placeholder'=>__('Enter State')))}}
            </div>
        </div>
        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{Form::label('billing_country',__('Country'),array('class'=>'form-label')) }}
                {{Form::text('billing_country',null,array('class'=>'form-control' , 'placeholder' => __('Enter Country')))}}

            </div>
        </div>


        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{Form::label('billing_zip',__('Zip Code'),array('class'=>'form-label')) }}
                {{Form::text('billing_zip',null,array('class'=>'form-control' , 'placeholder' => __('Enter Zip Code')))}}

            </div>
        </div>

    </div>

    @if(App\Models\Utility::getValByName('shipping_display')=='on')
        <div class="col-md-12 text-end mb-3">
            <input type="button" id="billing_data" value="{{__('Shipping Same As Billing')}}" class="btn btn-primary">
        </div>
        <h5 class="sub-title mb-3">{{__('Shipping Address')}}</h5>
        <div class="row">
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('shipping_name',__('Name'),array('class'=>'form-label')) }}
                    {{Form::text('shipping_name',null,array('class'=>'form-control' , 'placeholder'=> __('Enter Name')))}}

                </div>
            </div>
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('shipping_phone',__('Phone'),array('class'=>'form-label')) }}
                    {{Form::text('shipping_phone',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Phone')))}}

                </div>
            </div>
            <div class="col-md-12">
                <div class="form-group">
                    {{Form::label('shipping_address',__('Address'),array('class'=>'form-label')) }}
                    {{Form::textarea('shipping_address',null,array('class'=>'form-control','rows'=>3 , 'placeholder'=>__('Address')))}}
                </div>
            </div>


            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('shipping_city',__('City'),array('class'=>'form-label')) }}
                    {{Form::text('shipping_city',null,array('class'=>'form-control' , 'placeholder'=>__('Enter City')))}}

                </div>
            </div>
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('shipping_state',__('State'),array('class'=>'form-label')) }}
                    {{Form::text('shipping_state',null,array('class'=>'form-control' , 'placeholder'=>__('Enter State')))}}

                </div>
            </div>
            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('shipping_country',__('Country'),array('class'=>'form-label')) }}
                    {{Form::text('shipping_country',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Country')))}}

                </div>
            </div>


            <div class="col-lg-6 col-md-6 col-sm-6">
                <div class="form-group">
                    {{Form::label('shipping_zip',__('Zip Code'),array('class'=>'form-label')) }}
                    {{Form::text('shipping_zip',null,array('class'=>'form-control' , 'placeholder'=>__('Enter Zip Code')))}}
                </div>
            </div>

        </div>
    @endif
    
    <h5 class="sub-title">{{__('Additional Contacts')}}</h5>
    <div class="row">
        <div class="col-md-4">
             <div class="form-group">
                <label class="form-label">{{__('Contact Person Role')}}</label>
                <input type="text" name="contacts[0][role]" class="form-control" placeholder="e.g. Finance Director">
            </div>
        </div>
        <div class="col-md-4">
             <div class="form-group">
                <label class="form-label">{{__('Contact Person Name')}}</label>
                <input type="text" name="contacts[0][name]" class="form-control" placeholder="Name">
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label class="form-label">{{__('Contact Person Phone')}}</label>
                <input type="text" name="contacts[0][phone]" class="form-control" placeholder="Phone">
            </div>
        </div>
    </div>
    
    <button type="button" id="add_contact" class="btn btn-secondary mt-2">{{__('Add More Contact')}}</button>
    
    <hr>
    
    <h5 class="sub-title">{{__('Bank Account Details')}}</h5>
    <div class="row">
        <div class="col-md-6">
            <label class="form-label">{{__('Bank Name')}}</label>
            <input type="text" name="bank_account[bank_name]" class="form-control" placeholder="Bank Name">
        </div>
        <div class="col-md-6">
            <label class="form-label">{{__('Account Number')}}</label>
            <input type="text" name="bank_account[account_number]" class="form-control" placeholder="Account Number">
        </div>
        <div class="col-md-6 mt-3">
            <label class="form-label">{{__('Account Holder Name')}}</label>
            <input type="text" name="bank_account[account_holder]" class="form-control" placeholder="Account Holder">
        </div>
        <div class="col-md-6 mt-3">
            <label class="form-label">{{__('IFSC / SWIFT Code')}}</label>
            <input type="text" name="bank_account[ifsc]" class="form-control" placeholder="IFSC / SWIFT">
        </div>
    </div>
    
    <hr>
    
    <h5 class="sub-title">{{__('Additional Emails for Notifications')}}</h5>
    <div class="row">
        <div class="col-md-12">
            <input type="email" name="additional_emails[]" class="form-control" placeholder="Enter additional email" multiple>
            <small class="form-text text-muted">{{__('You can add multiple emails separated by commas')}}</small>
        </div>
    </div>
    
    <hr>
    
    <h5 class="sub-title">{{__('KYC Details')}}</h5>
    <div class="row">
        <div class="col-md-6">
            <label class="form-label">{{__('KYC Document Type')}}</label>
            <input type="text" name="kyc[document_type]" class="form-control" placeholder="e.g. Passport, ID Card">
        </div>
        <div class="col-md-6">
            <label class="form-label">{{__('KYC Document Number')}}</label>
            <input type="text" name="kyc[document_number]" class="form-control" placeholder="Document Number">
        </div>
        <div class="col-md-12 mt-3">
            <label class="form-label">{{__('KYC Document Upload')}}</label>
            <input type="file" name="kyc_document" class="form-control">
        </div>
    </div>


</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Create')}}" class="btn btn-primary">
</div>
{{Form::close()}}
<script>
    $(document).ready(function() {
        function toggleCreditTerms() {
            if ($('#payment_type').val() === 'credit') {
                $('#credit_terms_group').show();
            } else {
                $('#credit_terms_group').hide();
            }
        }

        $('#payment_type').on('change', toggleCreditTerms);
        toggleCreditTerms(); // run on page load
    });
    let contactIndex = 1;
    $('#add_contact').click(function() {
        let html = `<div class="row mt-3">
            <div class="col-md-4">
            <div class="form-group">
                <input type="text" name="contacts[${contactIndex}][role]" class="form-control" placeholder="e.g. Finance Director">
            </div>
            </div>
            <div class="col-md-4">
            <div class="form-group">
                <input type="text" name="contacts[${contactIndex}][name]" class="form-control" placeholder="Name">
            </div>
            </div>
            <div class="col-md-4">
            <div class="form-group">
                <input type="text" name="contacts[${contactIndex}][phone]" class="form-control" placeholder="Phone">
            </div>
            </div>
        </div>`;
        $(this).before(html);
        contactIndex++;
    });
</script>

