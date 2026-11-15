@extends('layouts.admin')
@section('page-title')
    {{ __('Edit Category') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('management-rules.index') }}">{{ __('Management Rules') }}</a></li>
    <li class="breadcrumb-item">{{ __('Edit Category') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('management-rules.update', $rule) }}">
                        @csrf
                        @method('PUT')
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Code') }} <span class="text-danger">*</span></label>
                                <input type="text" name="code" value="{{ old('code', $rule->code) }}" class="form-control @error('code') is-invalid @enderror" required>
                                @error('code')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" value="{{ old('name', $rule->name) }}" class="form-control @error('name') is-invalid @enderror" required>
                                @error('name')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('P&L Group') }} <span class="text-danger">*</span></label>
                                <select name="group" class="form-control @error('group') is-invalid @enderror" required>
                                    @foreach ($groups as $g)
                                        <option value="{{ $g }}" {{ old('group', $rule->group) === $g ? 'selected' : '' }}>{{ $g }}</option>
                                    @endforeach
                                </select>
                                @error('group')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">{{ __('Subgroup') }}</label>
                                <input type="text" name="subgroup" value="{{ old('subgroup', $rule->subgroup) }}" class="form-control @error('subgroup') is-invalid @enderror">
                                @error('subgroup')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <div class="text-end">
                            <a href="{{ route('management-rules.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
