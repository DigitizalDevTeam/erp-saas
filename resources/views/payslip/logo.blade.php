{{ Form::open(['route' => ['payslip.logo.store'], 'method' => 'POST', 'files' => true]) }}
    <div class="modal-body">
        <div class="row">
            @if (!empty($logoUrl))
                <div class="col-md-12 mb-3">
                    <div class="form-group">
                        {{ Form::label('', __('Current payslip logo'), ['class' => 'form-label']) }}
                        <div>
                            <img src="{{ $logoUrl }}" alt="{{ __('Payslip logo') }}" style="max-width: 200px; max-height: 80px;">
                        </div>
                    </div>
                </div>
            @endif
            <div class="col-md-12">
                <div class="form-group">
                    {{ Form::label('payslip_logo', __('Payslip logo'), ['class' => 'form-label']) }}
                    <input type="file" name="payslip_logo" id="payslip_logo" class="form-control" accept=".png,.jpg,.jpeg">
                    <small class="form-text text-muted">{{ __('PNG, JPG or JPEG, up to 20 MB. This logo shows on payslips; without it, the company logo is used.') }}</small>
                </div>
            </div>
            @if (!empty($logoUrl))
                <div class="col-md-12 mt-3">
                    <div class="form-check">
                        <input type="checkbox" name="remove_logo" id="remove_logo" value="1" class="form-check-input">
                        <label for="remove_logo" class="form-check-label">{{ __('Remove current payslip logo') }}</label>
                    </div>
                </div>
            @endif
        </div>
    </div>
    <div class="modal-footer">
        <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{ __('Save') }}" class="btn btn-primary">
    </div>
{{ Form::close() }}
