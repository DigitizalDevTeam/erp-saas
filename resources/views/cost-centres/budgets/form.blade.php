@extends('layouts.admin')
@section('page-title')
    {{ $budget->exists ? __('Edit Budget') : __('Create Budget') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centre-budgets.index') }}">{{ __('Budgets') }}</a></li>
    <li class="breadcrumb-item">{{ $budget->exists ? __('Edit') : __('Create') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $budget->exists ? route('cost-centre-budgets.update', $budget) : route('cost-centre-budgets.store') }}">
                        @csrf
                        @if ($budget->exists)
                            @method('PUT')
                        @endif

                        <div class="form-group">
                            <label class="form-label" for="cost_centre_id">{{ __('Cost Centre') }}</label>
                            <select name="cost_centre_id" id="cost_centre_id" class="form-control" {{ $budget->exists ? 'disabled' : '' }} required>
                                @foreach ($centres as $centre)
                                    <option value="{{ $centre->id }}" {{ (int) old('cost_centre_id', $budget->cost_centre_id) === (int) $centre->id ? 'selected' : '' }}>{{ $centre->code }} — {{ $centre->name }}</option>
                                @endforeach
                                @if (! $budget->exists && Auth::user()->can('create cost centres'))
                                    <option value="__create__">+ {{ __('Create Cost Centre') }}</option>
                                @endif
                            </select>
                            @if ($budget->exists)
                                <small class="text-muted">{{ __('The centre cannot change after creation — a different centre is a different budget.') }}</small>
                            @endif

                            <div id="new-cost-centre-panel" class="border rounded p-3 mt-2" style="display: none;">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label class="form-label" for="new_cost_centre_code">{{ __('Code') }}</label>
                                            <input type="text" id="new_cost_centre_code" class="form-control" maxlength="50" placeholder="{{ __('e.g. ADM') }}">
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="form-group">
                                            <label class="form-label" for="new_cost_centre_name">{{ __('Name') }}</label>
                                            <input type="text" id="new_cost_centre_name" class="form-control" maxlength="255" placeholder="{{ __('e.g. Administration') }}">
                                        </div>
                                    </div>
                                </div>
                                <div id="new-cost-centre-message" class="small mt-1" role="alert"></div>
                                <div class="mt-2">
                                    <button type="button" id="save-new-cost-centre-btn" class="btn btn-sm btn-primary">{{ __('Save Cost Centre') }}</button>
                                    <button type="button" id="cancel-new-cost-centre-btn" class="btn btn-sm btn-light">{{ __('Cancel') }}</button>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="fiscal_year">{{ __('Fiscal year') }}</label>
                                    <input type="number" name="fiscal_year" id="fiscal_year" class="form-control" min="2000" max="2100" value="{{ old('fiscal_year', $budget->fiscal_year) }}" {{ $budget->exists ? 'disabled' : '' }} required>
                                    <small class="text-muted">{{ __('Calendar year — the app closes the year on Dec 31.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="period">{{ __('Month') }}</label>
                                    <select name="period" id="period" class="form-control" {{ $budget->exists ? 'disabled' : '' }} required>
                                        @for ($m = 1; $m <= 12; $m++)
                                            <option value="{{ $m }}" {{ (int) old('period', $budget->period) === $m ? 'selected' : '' }}>{{ DateTime::createFromFormat('!m', (string) $m)->format('F') }}</option>
                                        @endfor
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="gl_account_id">{{ __('GL account (optional)') }}</label>
                                    <select name="gl_account_id" id="gl_account_id" class="form-control" {{ $budget->exists ? 'disabled' : '' }}>
                                        <option value="">{{ __('Whole centre — all expense accounts') }}</option>
                                        @foreach ($accounts as $account)
                                            <option value="{{ $account->id }}" {{ (int) old('gl_account_id', $budget->gl_account_id) === (int) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="project_id">{{ __('Project (optional)') }}</label>
                                    <select name="project_id" id="project_id" class="form-control" {{ $budget->exists ? 'disabled' : '' }}>
                                        <option value="">{{ __('All projects') }}</option>
                                        @foreach ($projects as $project)
                                            <option value="{{ $project->id }}" {{ (int) old('project_id', $budget->project_id) === (int) $project->id ? 'selected' : '' }}>{{ $project->project_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="budget_amount">{{ __('Budget amount') }}</label>
                            <input type="number" step="0.01" min="0" name="budget_amount" id="budget_amount" class="form-control" value="{{ old('budget_amount', $budget->budget_amount) }}" required>
                            <small class="text-muted">{{ __('The planned figure. It only limits spending once the budget is approved.') }}</small>
                        </div>

                        <div class="form-group mt-3">
                            <button type="submit" class="btn btn-primary">{{ $budget->exists ? __('Update Budget') : __('Create Budget') }}</button>
                            <a href="{{ route('cost-centre-budgets.index') }}" class="btn btn-light">{{ __('Back to List') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
    (function () {
        // Inline "Create Cost Centre" from the Cost Centre dropdown.
        var centreSelect = document.getElementById('cost_centre_id');
        var newCentrePanel = document.getElementById('new-cost-centre-panel');
        var newCentreCode = document.getElementById('new_cost_centre_code');
        var newCentreName = document.getElementById('new_cost_centre_name');
        var newCentreMessage = document.getElementById('new-cost-centre-message');
        var saveNewCentreBtn = document.getElementById('save-new-cost-centre-btn');
        var cancelNewCentreBtn = document.getElementById('cancel-new-cost-centre-btn');
        var previousCentre = centreSelect ? centreSelect.value : '';

        function showCostCentreMessage(text, ok) {
            if (! newCentreMessage) {
                return;
            }
            newCentreMessage.textContent = text;
            newCentreMessage.style.color = ok ? 'green' : 'red';
        }

        if (centreSelect) {
            centreSelect.addEventListener('change', function () {
                var option = centreSelect.options[centreSelect.selectedIndex];
                if (! option) {
                    return;
                }
                if (option.value === '__create__') {
                    if (newCentrePanel) {
                        newCentrePanel.style.display = '';
                    }
                    if (newCentreCode) {
                        newCentreCode.focus();
                    }
                    return;
                }
                previousCentre = centreSelect.value;
                if (newCentrePanel) {
                    newCentrePanel.style.display = 'none';
                }
            });
        }

        if (cancelNewCentreBtn) {
            cancelNewCentreBtn.addEventListener('click', function () {
                if (newCentrePanel) {
                    newCentrePanel.style.display = 'none';
                }
                if (newCentreCode) {
                    newCentreCode.value = '';
                }
                if (newCentreName) {
                    newCentreName.value = '';
                }
                showCostCentreMessage('', true);
                if (centreSelect) {
                    centreSelect.value = previousCentre;
                }
            });
        }

        if (saveNewCentreBtn) {
            saveNewCentreBtn.addEventListener('click', function () {
                var code = newCentreCode ? newCentreCode.value.trim() : '';
                var name = newCentreName ? newCentreName.value.trim() : '';
                if (! code) {
                    showCostCentreMessage('{{ __('Cost centre code is required.') }}', false);
                    if (newCentreCode) {
                        newCentreCode.focus();
                    }
                    return;
                }
                if (! name) {
                    showCostCentreMessage('{{ __('Cost centre name is required.') }}', false);
                    if (newCentreName) {
                        newCentreName.focus();
                    }
                    return;
                }
                saveNewCentreBtn.disabled = true;
                var token = document.querySelector('meta[name="csrf-token"]');
                fetch('{{ route('cost-centres.quick-store') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''
                    },
                    body: JSON.stringify({ code: code, name: name })
                }).then(function (response) {
                    return response.json().then(function (data) {
                        return { status: response.status, data: data };
                    });
                }).then(function (result) {
                    saveNewCentreBtn.disabled = false;
                    if (result.status === 201) {
                        var centre = result.data;
                        var option = document.createElement('option');
                        option.value = centre.id;
                        option.textContent = centre.code + ' — ' + centre.name;
                        var createOption = centreSelect.querySelector('option[value="__create__"]');
                        if (createOption) {
                            centreSelect.insertBefore(option, createOption);
                        } else {
                            centreSelect.appendChild(option);
                        }
                        centreSelect.value = String(centre.id);
                        previousCentre = String(centre.id);
                        if (newCentrePanel) {
                            newCentrePanel.style.display = 'none';
                        }
                        if (newCentreCode) {
                            newCentreCode.value = '';
                        }
                        if (newCentreName) {
                            newCentreName.value = '';
                        }
                        showCostCentreMessage(result.data.message || '{{ __('Cost centre created successfully.') }}', true);
                        centreSelect.dispatchEvent(new Event('change'));
                    } else {
                        var message = (result.data.errors && ((result.data.errors.code && result.data.errors.code[0]) || (result.data.errors.name && result.data.errors.name[0])))
                            || result.data.message
                            || '{{ __('Could not create the cost centre. Please try again.') }}';
                        showCostCentreMessage(message, false);
                    }
                }).catch(function () {
                    saveNewCentreBtn.disabled = false;
                    showCostCentreMessage('{{ __('Could not create the cost centre. Please try again.') }}', false);
                });
            });
        }

        // Never submit the budget form while "Create Cost Centre" is
        // still selected but unsaved.
        var budgetForm = centreSelect ? centreSelect.closest('form') : null;
        if (budgetForm) {
            budgetForm.addEventListener('submit', function (event) {
                if (centreSelect && centreSelect.value === '__create__') {
                    event.preventDefault();
                    showCostCentreMessage('{{ __('Please save or cancel the new cost centre first.') }}', false);
                    if (newCentreCode) {
                        newCentreCode.focus();
                    }
                }
            });
        }
    })();
</script>
@endpush
