@extends('layouts.admin')

@section('page-title')
    {{ __('HRM tax report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('HRM tax report') }}</li>
@endsection

@push('script-page')
    <script type="text/javascript" src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
    <script>
        function hrmTaxSavePdf() {
            var el = document.getElementById('printableArea');
            if (!el) return;
            var opt = {
                margin: 0.3,
                filename: 'hrm-tax-report_' + new Date().toISOString().slice(0, 10) + '.pdf',
                image: { type: 'jpeg', quality: 0.95 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
            };
            html2pdf().set(opt).from(el).save();
        }
    </script>
@endpush

@section('action-btn')
    <div class="float-end">
        <a href="#" class="btn btn-sm btn-primary" onclick="hrmTaxSavePdf(); return false;" data-bs-toggle="tooltip"
            title="{{ __('Download PDF (browser)') }}">
            <i class="ti ti-download"></i> {{ __('PDF') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card" id="hrm_tax_filters">
                <div class="card-body">
                    {{ Form::open(['route' => 'report.hrm.tax', 'method' => 'get', 'id' => 'hrm_tax_report_form']) }}
                    <div class="row align-items-end g-2">
                        <div class="col-md-2">
                            {{ Form::label('date_from', __('From (month)'), ['class' => 'form-label']) }}
                            {{ Form::month('date_from', $filters['date_from'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-2">
                            {{ Form::label('date_to', __('To (month)'), ['class' => 'form-label']) }}
                            {{ Form::month('date_to', $filters['date_to'], ['class' => 'form-control']) }}
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                            <select name="branch_id" id="branch_id" class="form-control">
                                <option value="">{{ __('All branches') }}</option>
                                @foreach ($branches as $bid => $bname)
                                    <option value="{{ $bid }}" @selected((int) ($filters['branch_id'] ?? 0) === (int) $bid)>{{ $bname }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            {{ Form::label('department_id', __('Department'), ['class' => 'form-label']) }}
                            <select name="department_id" id="department_id" class="form-control">
                                <option value="">{{ __('All departments') }}</option>
                                @foreach ($departments as $did => $dname)
                                    <option value="{{ $did }}" @selected((int) ($filters['department_id'] ?? 0) === (int) $did)>{{ $dname }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="ti ti-search"></i> {{ __('Apply') }}
                            </button>
                        </div>
                    </div>
                    {{ Form::close() }}
                    <p class="small text-muted mb-0 mt-3">
                        {{ __('Shows IRPS and INSS (employee) withheld per payslip month, loan deductions from payroll, and active employee loans with outstanding balance and scheduled repayments.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3" id="printableArea">
        <div class="col-12">
            <div class="text-center mb-3">
                <div class="fw-bold" style="font-size: 1.1rem;">{{ __('HRM tax & deductions report') }}</div>
                <div class="text-muted small">
                    {{ __('Period') }}: <strong>{{ $filters['date_from'] }}</strong> — <strong>{{ $filters['date_to'] }}</strong>
                </div>
            </div>

            <div class="row g-2 mb-4">
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border shadow-none mb-0 h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">{{ __('IRPS withheld (payslip)') }}</div>
                            <div class="fw-bold">{{ \Auth::user()->priceFormat($withholdingTotals['irps_payslip']) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border shadow-none mb-0 h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">{{ __('IRPS calculated') }}</div>
                            <div class="fw-bold">{{ \Auth::user()->priceFormat($withholdingTotals['irps_calculated']) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border shadow-none mb-0 h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">{{ __('INSS (employee)') }}</div>
                            <div class="fw-bold">{{ \Auth::user()->priceFormat($withholdingTotals['inss_employee']) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border shadow-none mb-0 h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">{{ __('Loan deducted (period)') }}</div>
                            <div class="fw-bold">{{ \Auth::user()->priceFormat($withholdingTotals['loan_deducted']) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border shadow-none mb-0 h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">{{ __('Loans outstanding') }}</div>
                            <div class="fw-bold">{{ \Auth::user()->priceFormat($loanTotals['outstanding']) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border shadow-none mb-0 h-100">
                        <div class="card-body py-2 px-3">
                            <div class="text-muted small">{{ __('Scheduled repayment / mo.') }}</div>
                            <div class="fw-bold">{{ \Auth::user()->priceFormat($loanTotals['monthly_installment']) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header py-2">
                    <h6 class="mb-0">{{ __('Withholdings by payslip (who / when / how much)') }}</h6>
                </div>
                <div class="card-body p-0">
                    @if (count($withholdingLines) === 0)
                        <div class="p-4 text-center text-muted">{{ __('No payslips in this period for the selected filters.') }}</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0 align-middle" style="font-size: 12px;">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Payroll month') }}</th>
                                        <th>{{ __('Emp. ID') }}</th>
                                        <th>{{ __('Name') }}</th>
                                        <th>{{ __('NUIT') }}</th>
                                        <th class="text-end">{{ __('Gross (IRPS base)') }}</th>
                                        <th class="text-end">{{ __('INSS emp.') }}</th>
                                        <th class="text-end">{{ __('IRPS (payslip)') }}</th>
                                        <th class="text-end">{{ __('IRPS (calc.)') }}</th>
                                        <th class="text-end">{{ __('Loan ded.') }}</th>
                                        <th class="text-end">{{ __('Net pay') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($withholdingLines as $line)
                                        <tr>
                                            <td>{{ $line['salary_month'] }}</td>
                                            <td>{{ $line['employee_code'] }}</td>
                                            <td>{{ $line['name'] }}</td>
                                            <td>{{ $line['nuit'] }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($line['gross_irps_base']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($line['inss_employee']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($line['irps_payslip']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($line['irps_calculated']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($line['loan_deducted']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($line['net']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="table-secondary fw-bold">
                                    <tr>
                                        <td colspan="4">{{ __('TOTAL') }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($withholdingTotals['gross_irps_base']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($withholdingTotals['inss_employee']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($withholdingTotals['irps_payslip']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($withholdingTotals['irps_calculated']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($withholdingTotals['loan_deducted']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($withholdingTotals['net']) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header py-2">
                    <h6 class="mb-0">{{ __('Employee loans — repayment & remaining') }}</h6>
                </div>
                <div class="card-body p-0">
                    @if ($loans->isEmpty())
                        <div class="p-4 text-center text-muted">{{ __('No loan records.') }}</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0 align-middle" style="font-size: 12px;">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Employee') }}</th>
                                        <th>{{ __('Loan') }}</th>
                                        <th class="text-end">{{ __('Principal') }}</th>
                                        <th class="text-end">{{ __('Outstanding') }}</th>
                                        <th class="text-end">{{ __('Per month') }}</th>
                                        <th class="text-center">{{ __('Paid inst.') }}</th>
                                        <th class="text-center">{{ __('Rem. inst.') }}</th>
                                        <th>{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($loans as $loan)
                                        @php
                                            $emp = $loan->employee;
                                            $principal = (float) ($loan->principal_amount ?? $loan->amount ?? 0);
                                            $out = $loan->outstanding_principal;
                                            $term = (int) ($loan->term_months ?? 0);
                                            $paid = (int) ($loan->installments_paid ?? 0);
                                            $remInst = $term > 0 ? max(0, $term - $paid) : null;
                                        @endphp
                                        <tr>
                                            <td>
                                                {{ $emp?->name }}
                                                @if ($emp?->employee_id)
                                                    <span class="text-muted">({{ $emp->employee_id }})</span>
                                                @endif
                                            </td>
                                            <td>{{ $loan->title }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($principal) }}</td>
                                            <td class="text-end">
                                                @if ($out !== null)
                                                    {{ \Auth::user()->priceFormat((float) $out) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat((float) ($loan->monthly_installment ?? 0)) }}</td>
                                            <td class="text-center">{{ $paid ?: '—' }}</td>
                                            <td class="text-center">{{ $remInst !== null ? $remInst : '—' }}</td>
                                            <td>{{ $loan->loan_status ? ucfirst((string) $loan->loan_status) : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="table-secondary fw-bold">
                                    <tr>
                                        <td colspan="2">{{ __('TOTAL') }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($loanTotals['principal']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($loanTotals['outstanding']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($loanTotals['monthly_installment']) }}</td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <p class="small text-muted mt-2 mb-0">
                {{ __('IRPS (calc.) uses the same progressive withholding rules as Model 22. Outstanding loan total only includes loans that track principal balance.') }}
            </p>
        </div>
    </div>
@endsection
