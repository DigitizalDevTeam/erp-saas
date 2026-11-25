{{Form::open(array('url'=>'irps-withholding','method'=>'post', 'class'=>'needs-validation', 'novalidate'))}}
    <div class="modal-body">
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    {{Form::label('salary_from',__('Salary From'),['class'=>'form-label'])}}<x-required></x-required>
                    {{Form::number('salary_from',null,array('class'=>'form-control','step'=>'0.01','min'=>'0','placeholder'=>__('e.g. 32750'),'required'=> 'required'))}}
                    @error('salary_from')
                        <span class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{Form::label('salary_to',__('Salary To'),['class'=>'form-label'])}}
                    {{Form::number('salary_to',null,array('class'=>'form-control','step'=>'0.01','min'=>'0','placeholder'=>__('Leave empty for no upper limit')))}}
                    @error('salary_to')
                        <span class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{Form::label('base_dep0',__('Base (0 dependents)'),['class'=>'form-label'])}}
                    {{Form::number('base_dep0',null,array('class'=>'form-control','step'=>'0.01','min'=>'0','placeholder'=>__('Leave empty for no withholding')))}}
                    @error('base_dep0')
                        <span class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{Form::label('base_dep1',__('Base (1 dependent)'),['class'=>'form-label'])}}
                    {{Form::number('base_dep1',null,array('class'=>'form-control','step'=>'0.01','min'=>'0','placeholder'=>__('Leave empty for no withholding')))}}
                    @error('base_dep1')
                        <span class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{Form::label('base_dep2',__('Base (2 dependents)'),['class'=>'form-label'])}}
                    {{Form::number('base_dep2',null,array('class'=>'form-control','step'=>'0.01','min'=>'0','placeholder'=>__('Leave empty for no withholding')))}}
                    @error('base_dep2')
                        <span class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{Form::label('base_dep3',__('Base (3 dependents)'),['class'=>'form-label'])}}
                    {{Form::number('base_dep3',null,array('class'=>'form-control','step'=>'0.01','min'=>'0','placeholder'=>__('Leave empty for no withholding')))}}
                    @error('base_dep3')
                        <span class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{Form::label('base_dep4plus',__('Base (4+ dependents)'),['class'=>'form-label'])}}
                    {{Form::number('base_dep4plus',null,array('class'=>'form-control','step'=>'0.01','min'=>'0','placeholder'=>__('Leave empty for no withholding')))}}
                    @error('base_dep4plus')
                        <span class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{Form::label('coefficient',__('Coefficient'),['class'=>'form-label'])}}
                    {{Form::number('coefficient',null,array('class'=>'form-control','step'=>'0.0001','min'=>'0','max'=>'1','placeholder'=>__('e.g. 0.20')))}}
                    @error('coefficient')
                        <span class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn  btn-primary">
    </div>
{{Form::close()}}
