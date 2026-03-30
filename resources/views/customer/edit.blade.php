<!--{{Form::model($customer,array('route' => array('customer.update', $customer->id), 'method' => 'PUT', 'class'=>'needs-validation', 'novalidate')) }}-->
{{ Form::model($customer, ['url' => ['customer', $customer->id], 'method' => 'put', 'class' => 'needs-validation', 'novalidate', 'enctype' => 'multipart/form-data']) }}

<div class="modal-body">

    <h5 class="sub-title">{{__('Basic Info')}}</h5>
    <div class="row">
        <div class="col-lg-4 col-md-4 col-sm-6">
            <div class="form-group">
                {{Form::label('name',__('Name'),array('class'=>'form-label')) }}<x-required></x-required>
                {{Form::text('name',null,array('class'=>'form-control','required'=>'required','placeholder'=>__('Enter Name')))}}

            </div>
        </div>
        <div class="col-lg-4 col-md-4 col-sm-6">
            <div class="form-group">
                <x-mobile label="{{__('Contact')}}" name="contact" value="{{$customer->contact}}" required placeholder="Enter Contact"></x-mobile>
            </div>
        </div>
        <div class="col-lg-4 col-md-4 col-sm-6">
            <div class="form-group">
                {{Form::label('email',__('Email'),['class'=>'form-label'])}}<x-required></x-required>
                {{Form::text('email',null,array('class'=>'form-control','required'=>'required', 'placeholder'=>__('Enter email')))}}

            </div>
        </div>

        @include('partials.contact_notification_preferences_customer', ['customer' => $customer])

        <div class="col-lg-6 col-md-6 col-sm-6">
            <div class="form-group">
                {{Form::label('tax_number',__('Tax Number'),['class'=>'form-label'])}}
                {{Form::text('tax_number',null,array('class'=>'form-control', 'placeholder' => __('Enter Tax Number')))}}

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
                        <option value="{{ $level->id }}" {{ $level->id == $customer->price_level_id ? 'selected' : '' }}>{{ $level->name }} - {{ $type }} - {{ $value }}</option>
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

    <h5 class="sub-title">{{__('Billing Address')}}</h5>
     <div class="billing_addresses mb-3">
        @if(count($customer->billingAddresses) > 0)
            @foreach($customer->billingAddresses as $key => $address)
                <div class="row billing_address mb-3">
                    <div class="col-lg-6 col-md-6 col-sm-6">
                        <div class="form-group">
                            {{ Form::label('billing_name', __('Name'), ['class' => 'form-label']) }}
                            {{ Form::text('billing_name[]', $address->name, ['class' => 'form-control', 'placeholder' => __('Enter Name')]) }}
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6 col-sm-6">
                        <div class="form-group">
                            {{ Form::label('billing_phone', __('Phone'), ['class' => 'form-label']) }}
                            {{ Form::text('billing_phone[]', $address->phone, ['class' => 'form-control', 'placeholder' => __('Enter Phone')]) }}
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="form-group">
                            {{ Form::label('billing_address', __('Address'), ['class' => 'form-label']) }}
                            {{ Form::textarea('billing_address[]', $address->address, ['class' => 'form-control', 'rows' => 3, 'placeholder' => __('Enter Address')]) }}
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6 col-sm-6">
                        <div class="form-group">
                            {{ Form::label('billing_city', __('City'), ['class' => 'form-label']) }}
                            {{ Form::text('billing_city[]', $address->city, ['class' => 'form-control', 'placeholder' => __('Enter City')]) }}
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6 col-sm-6">
                        <div class="form-group">
                            {{ Form::label('billing_state', __('State'), ['class' => 'form-label']) }}
                            {{ Form::text('billing_state[]', $address->state, ['class' => 'form-control', 'placeholder' => __('Enter State')]) }}
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6 col-sm-6">
                        <div class="form-group">
                            {{ Form::label('billing_country', __('Country'), ['class' => 'form-label']) }}
                            {{ Form::text('billing_country[]', $address->country, ['class' => 'form-control', 'placeholder' => __('Enter Country')]) }}
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6 col-sm-6">
                        <div class="form-group">
                            {{ Form::label('billing_zip', __('Zip Code'), ['class' => 'form-label']) }}
                            {{ Form::text('billing_zip[]', $address->zip, ['class' => 'form-control', 'placeholder' => __('Enter Zip Code')]) }}
                        </div>
                    </div>
                    @if($key > 0)
                        <div class="col-lg-6 col-md-6 col-sm-6">
                            <button type="button" class="btn btn-primary d-flex p-2 remove_billing_address">
                                <i class="ti ti-trash"></i>
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach
        @else
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
        @endif
        
        <button type="button" class="btn btn-primary d-flex p-2 add_billing_address">
            <i class="ti ti-plus"></i>
        </button>

    </div>

    @if(App\Models\Utility::getValByName('shipping_display')=='on')
        {{-- <div class="col-md-12 text-end">
            <input type="button" id="billing_data" value="{{__('Shipping Same As Billing')}}" class="btn btn-primary">
        </div> --}}
        <h5 class="sub-title">{{__('Shipping Address')}}</h5>
        <div class="shipping_addresses mb-3">
            @if(count($customer->shippingAddresses) > 0)
                @foreach($customer->shippingAddresses as $key => $address)
                    <div class="row shipping_address mb-3">
                        <div class="col-lg-6 col-md-6 col-sm-6">
                            <div class="form-group">
                                {{ Form::label('shipping_name', __('Name'), ['class' => 'form-label']) }}
                                {{ Form::text('shipping_name[]', $address->name, ['class' => 'form-control', 'placeholder' => __('Enter Name')]) }}
                            </div>
                        </div>
                    
                        <div class="col-lg-6 col-md-6 col-sm-6">
                            <div class="form-group">
                                {{ Form::label('shipping_phone', __('Phone'), ['class' => 'form-label']) }}
                                {{ Form::text('shipping_phone[]', $address->phone, ['class' => 'form-control', 'placeholder' => __('Enter Phone')]) }}
                            </div>
                        </div>
                    
                        <div class="col-md-12">
                            <div class="form-group">
                                {{ Form::label('shipping_address', __('Address'), ['class' => 'form-label']) }}
                                {{ Form::textarea('shipping_address[]', $address->address, ['class' => 'form-control', 'rows' => 3, 'placeholder' => __('Enter Address')]) }}
                            </div>
                        </div>
                    
                        <div class="col-lg-6 col-md-6 col-sm-6">
                            <div class="form-group">
                                {{ Form::label('shipping_city', __('City'), ['class' => 'form-label']) }}
                                {{ Form::text('shipping_city[]', $address->city, ['class' => 'form-control', 'placeholder' => __('Enter City')]) }}
                            </div>
                        </div>
                    
                        <div class="col-lg-6 col-md-6 col-sm-6">
                            <div class="form-group">
                                {{ Form::label('shipping_state', __('State'), ['class' => 'form-label']) }}
                                {{ Form::text('shipping_state[]', $address->state, ['class' => 'form-control', 'placeholder' => __('Enter State')]) }}
                            </div>
                        </div>
                    
                        <div class="col-lg-6 col-md-6 col-sm-6">
                            <div class="form-group">
                                {{ Form::label('shipping_country', __('Country'), ['class' => 'form-label']) }}
                                {{ Form::text('shipping_country[]', $address->country, ['class' => 'form-control', 'placeholder' => __('Enter Country')]) }}
                            </div>
                        </div>
                    
                        <div class="col-lg-6 col-md-6 col-sm-6">
                            <div class="form-group">
                                {{ Form::label('shipping_zip', __('Zip Code'), ['class' => 'form-label']) }}
                                {{ Form::text('shipping_zip[]', $address->zip, ['class' => 'form-control', 'placeholder' => __('Enter Zip Code')]) }}
                            </div>
                        </div>
                        @if($key > 0)
                        <div class="col-lg-6 col-md-6 col-sm-6">
                            <button type="button" class="btn btn-primary d-flex p-2 remove_shipping_address">
                                <i class="ti ti-trash"></i>
                            </button>
                        </div>
                        @endif
                    </div>
                @endforeach
            @else
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
            @endif

            <button type="button" class="btn btn-primary d-flex p-2 add_shipping_address">
                <i class="ti ti-plus"></i>
            </button>
        </div>
    @endif
    
    <h5 class="sub-title">{{__('Additional Contacts')}}</h5>
    @php
        $contacts = json_decode($customer->contacts, true) ?? [['role'=>'','name'=>'','phone'=>'']];
    @endphp
    @foreach ($contacts as $index => $contact)
    <div class="row">
        <div class="col-md-4">
             <div class="form-group">
                <label class="form-label">{{__('Contact Person Role')}}</label>
                <input type="text" name="contacts[{{ $index }}][role]" class="form-control" placeholder="e.g. Finance Director" value="{{ old("contacts.$index.role", $contact['role'] ?? '') }}">
            </div>
        </div>
        <div class="col-md-4">
             <div class="form-group">
                <label class="form-label">{{__('Contact Person Name')}}</label>
                <input type="text" name="contacts[{{ $index }}][name]" class="form-control" placeholder="Name" value="{{ old("contacts.$index.name", $contact['name'] ?? '') }}">
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label class="form-label">{{__('Contact Person Phone')}}</label>
                <input type="text" name="contacts[{{ $index }}][phone]" class="form-control" placeholder="Phone" value="{{ old("contacts.$index.phone", $contact['phone'] ?? '') }}">
            </div>
        </div>
    </div>
    @endforeach
    <button type="button" id="add_contact" class="btn btn-secondary mt-2">{{__('Add More Contact')}}</button>
    
    <hr>
    
    <h5 class="sub-title">{{__('Bank Account Details')}}</h5>
    @php
        $bankAccount = [];
        if (!empty($customer->bank_account)) {
            $bankAccount = json_decode($customer->bank_account, true);
            if (!is_array($bankAccount)) {
                $bankAccount = [];
            }
        }
    @endphp
    <div class="row">
        <div class="col-md-6">
            <label class="form-label">{{__('Bank Name')}}</label>
            <input type="text" name="bank_account[bank_name]" class="form-control" placeholder="Bank Name" value="{{ old('bank_account.bank_name', $bankAccount['bank_name'] ?? '') }}">
        </div>
        <div class="col-md-6">
            <label class="form-label">{{__('Account Number')}}</label>
            <input type="text" name="bank_account[account_number]" class="form-control" placeholder="Account Number" value="{{ old('bank_account.account_number', $bankAccount['account_number'] ?? '') }}">
        </div>
        <div class="col-md-6 mt-3">
            <label class="form-label">{{__('Account Holder Name')}}</label>
            <input type="text" name="bank_account[account_holder]" class="form-control" placeholder="Account Holder" value="{{ old('bank_account.account_holder', $bankAccount['account_holder'] ?? '') }}">
        </div>
        <div class="col-md-6 mt-3">
            <label class="form-label">{{__('IFSC / SWIFT Code')}}</label>
            <input type="text" name="bank_account[ifsc]" class="form-control" placeholder="IFSC / SWIFT" value="{{ old('bank_account.ifsc', $bankAccount['ifsc'] ?? '') }}">
        </div>
    </div>
    
    <hr>
    
    <h5 class="sub-title">{{__('Additional Emails for Notifications')}}</h5>
    @php
        $emails = [];
        if (!empty($customer->additional_emails)) {
            $emails = json_decode($customer->additional_emails, true);
            if (!is_array($emails)) {
                $emails = [];
            }
        }
    @endphp
    <div class="row">
        <div class="col-md-12">
            <input type="email" name="additional_emails[]" class="form-control" placeholder="Enter additional email" value="{{ old('additional_emails.0', implode(',', $emails)) }}" multiple>
            <small class="form-text text-muted">{{__('You can add multiple emails separated by commas')}}</small>
        </div>
    </div>
    
    <hr>
    
    <h5 class="sub-title">{{__('KYC Details')}}</h5>
    @php
        $kyc = [];
        if (!empty($customer->kyc)) {
            $kyc = json_decode($customer->kyc, true);
            if (!is_array($kyc)) {
                $kyc = [];
            }
        }
    @endphp
    <div class="row">
        <div class="col-md-6">
            <label class="form-label">{{__('KYC Document Type')}}</label>
            <input type="text" name="kyc[document_type]" class="form-control" placeholder="e.g. Passport, ID Card" value="{{ old('kyc.document_type', $kyc['document_type'] ?? '') }}">
        </div>
        <div class="col-md-6">
            <label class="form-label">{{__('KYC Document Number')}}</label>
            <input type="text" name="kyc[document_number]" class="form-control" placeholder="Document Number" value="{{ old('kyc.document_number', $kyc['document_number'] ?? '') }}">
        </div>
        <div class="col-md-12 mt-3">
            <label class="form-label">{{__('KYC Document Upload')}}</label>
            @if(!empty($customer->kyc_document_path))
                <a href="{{ asset('storage/' . $customer->kyc_document_path) }}" target="_blank">View Existing Document</a><br>
            @endif
            <input type="file" name="kyc_document" class="form-control">
        </div>
    </div>


</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn btn-primary">
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
        
        $(document).on("click", ".add_billing_address", function() {
            var newAddress = $(".billing_address").first().clone();
            newAddress.find('input, textarea, select').val('');
            newAddress.append(`<div class="col-lg-6 col-md-6 col-sm-6">
                                <button type="button" class="btn btn-primary d-flex p-2 remove_billing_address">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </div>`);
            $(newAddress).addClass("mb-3");
            $(".billing_address").last().after(newAddress);
        });
        
        $(document).on("click", ".remove_billing_address", function() {
            $(this).parent().parent().remove();         
        });
    
        $(document).on("click", ".add_shipping_address", function() {
            var newShippingAddress = $(".shipping_address").first().clone();
            newShippingAddress.find('input, textarea, select').val('');
            newShippingAddress.append(`<div class="col-lg-6 col-md-6 col-sm-6">
                                <button type="button" class="btn btn-primary d-flex p-2 remove_shipping_address">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </div>`);
            $(newShippingAddress).addClass("mb-3");
            $(".shipping_address").last().after(newShippingAddress);
        });
        
        $(document).on("click", ".remove_shipping_address", function() {
            $(this).parent().parent().remove();         
        });
        
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