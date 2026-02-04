{{ Form::model($priceLevel, ['route' => ['price-levels.update', $priceLevel->id], 'method' => 'PUT', 'class'=>'needs-validation', 'novalidate']) }}
<div class="modal-body">
    <div class="row">
        {{-- Name --}}
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Price Level Name'), ['class' => 'form-label']) }}<x-required />
            {{ Form::text('name', null, ['class' => 'form-control font-style', 'required' => 'required', 'placeholder' => __('Enter Price Level Name')]) }}
            @error('name')
            <small class="invalid-name" role="alert">
                <strong class="text-danger">{{ $message }}</strong>
            </small>
            @enderror
        </div>

        {{-- Type --}}
        <div class="form-group col-md-6">
            {{ Form::label('type', __('Type'), ['class' => 'form-label']) }}<x-required />
            {{ Form::select('type', ['fixed' => 'Fixed', 'percentage' => 'Percentage'], null, ['class' => 'form-control', 'required' => 'required', 'id' => 'price-level-type']) }}
            @error('type')
            <small class="invalid-type" role="alert">
                <strong class="text-danger">{{ $message }}</strong>
            </small>
            @enderror
        </div>

        {{-- Value --}}
        <div class="form-group col-md-12 mt-3">
            {{ Form::label('value', __('Value'), ['class' => 'form-label']) }}<x-required />
            {{ Form::number('value', null, ['class' => 'form-control', 'required' => 'required', 'step' => '0.01', 'placeholder' => __('Enter Value'), 'id' => 'price-level-value']) }}
            <small id="value-help" class="form-text text-muted">Enter the amount or percentage based on selected type.</small>
            @error('value')
            <small class="invalid-value" role="alert">
                <strong class="text-danger">{{ $message }}</strong>
            </small>
            @enderror
        </div>
    </div>
</div>

<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn btn-primary">
</div>
{{ Form::close() }}

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const typeField = document.getElementById('price-level-type');
        const helpText = document.getElementById('value-help');

        function updateHelp() {
            if (typeField.value === 'fixed') {
                helpText.innerText = 'Enter a fixed amount (e.g., 100.00)';
            } else {
                helpText.innerText = 'Enter a percentage (e.g., 10 for 10%)';
            }
        }

        typeField.addEventListener('change', updateHelp);
        updateHelp(); // Initialize on load
    });
</script>
@endpush
