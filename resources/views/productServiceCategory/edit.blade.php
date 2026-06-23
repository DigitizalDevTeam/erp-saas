{{ Form::model($category, array('route' => array('product-category.update', $category->id), 'method' => 'PUT', 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">

    <div class="row">
        <div class="form-group col-md-12">
            {{ Form::label('name', __('Category Name'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::text('name', null, array('class' => 'form-control font-style','required'=>'required', 'placeholder'=>__('Enter Category Name'))) }}
        </div>

        <div class="form-group col-md-12 d-block">
            {{ Form::label('type', __('Category Type'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::select('type',$types,null, array('class' => 'form-control select cattype','required'=>'required')) }}
        </div>

        <div class="form-group col-md-12">
            {{ Form::label('parent_id', __('Parent Category'),['class'=>'form-label']) }}
            <select name="parent_id" id="parent_id" class="form-control select">
                <option value="">{{ __('None (Top level)') }}</option>
                @foreach(($parentOptions ?? []) as $optId => $pathLabel)
                    <option value="{{ $optId }}" {{ (int)($category->parent_id ?? 0) === (int)$optId ? 'selected' : '' }}>{{ $pathLabel }}</option>
                @endforeach
            </select>
            <small class="text-muted">{{ __('Category → Subcategory → Sub-subcategory: pick a parent, or leave top level.') }}</small>
        </div>


        <div class="form-group col-md-12 account {{$category->type =='product & service'? 'd-none':''}}">
            {{Form::label('chart_account_id',__('Account'),['class'=>'form-label'])}}
            <select class="form-control select" name="chart_account" id="chart_account" >
            </select>

        </div>



        <div class="form-group col-md-12 pos-print-routing-field {{ $category->type == 'product & service' ? '' : 'd-none' }}">
            {{ Form::label('print_zone', __('Default print destination (Restaurant)'), ['class' => 'form-label']) }}
            {{ Form::select('print_zone', ['' => __('Inherit / none'), 'kitchen' => __('Kitchen'), 'bar' => __('Bar'), 'both' => __('Both')], $category->print_zone ?? '', ['class' => 'form-control select']) }}
            <small class="text-muted">{{ __('Products in this category print here unless overridden on the product.') }}</small>
        </div>

        <div class="form-group col-md-12">
            {{ Form::label('color', __('Category Color'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::text('color', null, array('class' => 'form-control jscolor','required'=>'required')) }}
            <p class="small">{{__('For chart representation')}}</p>
        </div>

    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn  btn-primary">
</div>
{{ Form::close() }}


<script>

    //hide & show chartofaccount

    function toggleCategoryTypeFields(type) {
        if (type != 'product & service') {
            $('.account').removeClass('d-none').addClass('d-block');
            $('.pos-print-routing-field').addClass('d-none').removeClass('d-block');
        } else {
            $('.account').addClass('d-none').removeClass('d-block');
            $('.pos-print-routing-field').removeClass('d-none').addClass('d-block');
        }
    }

    $(document).on('click', '.cattype', function () {
        toggleCategoryTypeFields($(this).val());
    });

    $(document).on('change', '#type', function () {
        var type = $(this).val();
        toggleCategoryTypeFields(type);

        // filter parent options by selected type
        $('#parent_id option').each(function () {
            var optType = $(this).data('type');
            if (!optType) return; // placeholder
            $(this).prop('hidden', optType != type);
        });

        $.ajax({
            url: '{{route('productServiceCategory.getaccount')}}',
            type: 'POST',
            data: {
                "type": type,
                "_token": "{{ csrf_token() }}",
            },
            success: function (data) {
                $('#chart_account').empty();
                $('#chart_account').append('<option value="">{{__(' --- Select Account ---')}}</option>');
                $.each(data.chart_accounts, function (key, value) {
                    var select = '';
                    if (key == '{{ $category->chart_account_id }}') {
                        select = 'selected';
                    }
                    $('#chart_account').append('<option value="' + key + '"  ' + select + ' class="subAccount">' + value + '</option>');

                    $.each(data.sub_accounts, function (subkey, subvalue) {
                    var select1 = '';

                        if (subvalue.id == '{{ $category->chart_account_id }}') {
                        select1 = 'selected';
                    }
                        if(key == subvalue.account)
                        {
                            $('#chart_account').append('<option value="' + subvalue.id + '"  ' + select1 + '>' + '&nbsp; &nbsp;&nbsp;' + subvalue.name + '</option>');
                        }
                });
                });
            }
        });
    });
    $(document).ready(function (){
        $('#type').trigger('change')
    })
</script>
