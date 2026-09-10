@php
    use App\Models\Utility;
    $setting = \App\Models\Utility::settings();
    $logo = \App\Models\Utility::get_file('uploads/logo');

    $company_logo = $setting['company_logo_dark'] ?? '';
    $company_logos = $setting['company_logo_light'] ?? '';
    $company_small_logo = $setting['company_small_logo'] ?? '';

    $emailTemplate = \App\Models\EmailTemplate::emailTemplateData();
    $lang = Auth::user()->lang;

    $userPlan = \App\Models\Plan::getPlan(\Auth::user()->show_dashboard());
@endphp

@if (isset($setting['cust_theme_bg']) && $setting['cust_theme_bg'] == 'on')
    <nav class="dash-sidebar light-sidebar transprent-bg">
    @else
        <nav class="dash-sidebar light-sidebar ">
@endif
<div class="navbar-wrapper">
    <div class="m-header main-logo">
        <a href="#" class="b-brand">

            @if ($setting['cust_darklayout'] && $setting['cust_darklayout'] == 'on')
                <img src="{{ $logo . '/' . 'logo-light.png' . '?' . time() }}"
                    alt="{{ config('app.name', 'ERP-SaaS') }}" class="logo logo-lg">
            @else
                <img src="{{ $logo . '/' . 'logo-dark.png' . '?' . time() }}"
                    alt="{{ config('app.name', 'ERP-SaaS') }}" class="logo logo-lg">
            @endif

        </a>
    </div>
    <div class="navbar-content">
        @if (\Auth::user()->type != 'client')
            <ul class="dash-navbar">
                <!--------------------- Start Dashboard ----------------------------------->
                @if (menu_can_node('menu.dashboard') ||
                        Gate::check('show hrm dashboard') ||
                        Gate::check('show project dashboard') ||
                        Gate::check('show account dashboard') ||
                        Gate::check('show crm dashboard') ||
                        Gate::check('show sales dashboard') ||
                        Gate::check('show pos dashboard'))
                    <li
                        class="dash-item dash-hasmenu
                                {{ Request::segment(1) == null ||
                                Request::segment(1) == 'account-dashboard' ||
                                Request::segment(1) == 'hrm-dashboard' ||
                                Request::segment(1) == 'crm-dashboard' ||
                                Request::segment(1) == 'project-dashboard' ||
                                Request::segment(1) == 'account-statement-report' ||
                                Request::segment(1) == 'invoice-summary' ||
                                Request::segment(1) == 'sales' ||
                                Request::segment(1) == 'receivables' ||
                                Request::segment(1) == 'payables' ||
                                Request::segment(1) == 'bill-summary' ||
                                Request::segment(1) == 'product-stock-report' ||
                                Request::segment(1) == 'minimum-stock-alert' ||
                                Request::segment(1) == 'transaction' ||
                                Request::segment(1) == 'income-summary' ||
                                Request::segment(1) == 'expense-summary' ||
                                Request::segment(1) == 'income-vs-expense-summary' ||
                                Request::segment(1) == 'tax-summary' ||
                                Request::segment(1) == 'income report' ||
                                Request::segment(1) == 'report' ||
                                Request::segment(1) == 'reports-monthly-cashflow' ||
                                Request::segment(1) == 'reports-quarterly-cashflow' ||
                                Request::segment(1) == 'reports-payroll' ||
                                Request::segment(1) == 'reports-hrm-tax' ||
                                Request::segment(1) == 'report-leave' ||
                                Request::segment(1) == 'reports-monthly-attendance' ||
                                Request::segment(1) == 'reports-lead' ||
                                Request::segment(1) == 'reports-deal' ||
                                Request::segment(1) == 'sales-dashboard' ||
                                Request::segment(1) == 'pos-dashboard' ||
                                Request::segment(1) == 'reports-warehouse' ||
                                Request::segment(1) == 'reports-daily-purchase' ||
                                Request::segment(1) == 'reports-monthly-purchase' ||
                                Request::segment(1) == 'reports-daily-pos' ||
                                Request::segment(1) == 'reports-monthly-pos' ||
                                Request::segment(1) == 'reports-pos-vs-purchase' ||
                                Request::is('reports/product-system*') ||
                                Request::segment(1) == 'profit-loss-report' ||
                                Request::segment(1) == 'balance-sheet-report' ||
                                Request::segment(1) == 'ledger-report' ||
                                Request::segment(1) == 'trial-balance-report' ||
                                Request::segment(1) == 'reports-demonstracao-resultados'
                                    ? 'active dash-trigger'
                                    : '' }}">
                        <a href="#!" class="dash-link ">
                            <span class="dash-micon">
                                <i class="ti ti-home"></i>
                            </span>
                            <span class="dash-mtext">{{ __('Dashboard') }}</span>
                            <span class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                        <ul class="dash-submenu">
                            @if ($userPlan->account == 1 && menu_can_node('menu.dashboard.accounting'))
                                <li
                                    class="dash-item dash-hasmenu {{ Request::segment(1) == null || Request::segment(1) == 'account-dashboard' || Request::segment(1) == 'report' || Request::segment(1) == 'reports-monthly-cashflow' || Request::segment(1) == 'reports-quarterly-cashflow' || Request::segment(1) == 'profit-loss-report' || Request::segment(1) == 'balance-sheet-report' || Request::segment(1) == 'ledger-report' || Request::segment(1) == 'trial-balance-report' || Request::segment(1) == 'reports-demonstracao-resultados' || Request::route()->getName() == 'report.profit.loss' || Request::route()->getName() == 'report.balance.sheet' || Request::route()->getName() == 'report.ledger' || Request::route()->getName() == 'trial.balance' || Request::route()->getName() == 'report.dre.comparativa' ? ' active dash-trigger' : '' }}">
                                    <a class="dash-link" href="#">{{ __('Accounting ') }}<span
                                            class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                    <ul class="dash-submenu">
                                        @if (menu_can('menu.dashboard.accounting.overview'))
                                            <li
                                                class="dash-item {{ Request::segment(1) == null || Request::segment(1) == 'account-dashboard' ? ' active' : '' }}">
                                                <a class="dash-link"
                                                    href="{{ route('dashboard') }}">{{ __(' Overview') }}</a>
                                            </li>
                                        @endif
                                        @if (menu_can_node('menu.dashboard.accounting.reports'))
                                            <li
                                                class="dash-item dash-hasmenu {{ Request::segment(1) == 'report' || Request::segment(1) == 'reports-monthly-cashflow' || Request::segment(1) == 'reports-quarterly-cashflow' || Request::segment(1) == 'profit-loss-report' || Request::segment(1) == 'balance-sheet-report' || Request::segment(1) == 'ledger-report' || Request::segment(1) == 'trial-balance-report' || Request::segment(1) == 'reports-demonstracao-resultados' || Request::route()->getName() == 'report.profit.loss' || Request::route()->getName() == 'report.balance.sheet' || Request::route()->getName() == 'report.ledger' || Request::route()->getName() == 'trial.balance' || Request::route()->getName() == 'report.dre.comparativa' ? 'active dash-trigger ' : '' }}">
                                                <a class="dash-link" href="#">{{ __('Reports') }}<span
                                                        class="dash-arrow"><i
                                                            data-feather="chevron-right"></i></span></a>
                                                <ul class="dash-submenu">
                                                    {{-- Monthly (government) --}}
                                                    @if (menu_can_node('menu.dashboard.accounting.reports.monthly'))
                                                    <li class="dash-item dash-hasmenu">
                                                        <a class="dash-link" href="#">{{ __('Monthly Reports') }}
                                                            <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
                                                        </a>
                                                        <ul class="dash-submenu">
                                                            @if (menu_can('menu.dashboard.accounting.reports.monthly.vat_modelo_a'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'monthly.reports.vat-modelo-a' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('monthly.reports.vat-modelo-a') }}">{{ __('VAT (Modelo A IVA)') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.monthly.trial_balance'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'monthly.reports.trial-balance' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('monthly.reports.trial-balance') }}">{{ __('Trial Balance (Balancete)') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.monthly.irps_modelo_19'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'monthly.reports.irps-modelo-19' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('monthly.reports.irps-modelo-19') }}">{{ __('IRPS (Modelo 19)') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.monthly.irps_modelo_22'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'monthly.reports.irps-modelo-22' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('monthly.reports.irps-modelo-22') }}">{{ __('IRPS / Payroll (Model 22)') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.monthly.irpc_payment_guide'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'annual.reports.irpc-payment-guide' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('annual.reports.irpc-payment-guide') }}">{{ __('IRPC Payment Guide (Annual)') }}</a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                    @endif

                                                    {{-- Management (existing/current) --}}
                                                    @if (menu_can_node('menu.dashboard.accounting.reports.management'))
                                                    <li class="dash-item dash-hasmenu">
                                                        <a class="dash-link" href="#">{{ __('Management Reports') }}
                                                            <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
                                                        </a>
                                                        <ul class="dash-submenu">
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.account_statement'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'report.account.statement' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('report.account.statement') }}">{{ __('Account Statement') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.invoice_summary'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'report.invoice.summary' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('report.invoice.summary') }}">{{ __('Invoice Summary') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.sales'))
                                                            <li class="dash-item {{ Request::route()->getName() == 'report.sales' ? ' active' : '' }}">
                                                                <a class="dash-link" href="{{ route('report.sales') }}">{{ __('Sales Report') }}</a>
                                                            </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.receivables'))
                                                            <li class="dash-item {{ Request::route()->getName() == 'report.receivables' ? ' active' : '' }}">
                                                                <a class="dash-link" href="{{ route('report.receivables') }}">{{ __('Receivables') }}</a>
                                                            </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.payables'))
                                                            <li class="dash-item {{ Request::route()->getName() == 'report.payables' ? ' active' : '' }}">
                                                                <a class="dash-link" href="{{ route('report.payables') }}">{{ __('Payables') }}</a>
                                                            </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.bill_summary'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'report.bill.summary' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('report.bill.summary') }}">{{ __('Bill Summary') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.product_stock'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'report.product.stock.report' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('report.product.stock.report') }}">{{ __('Product Stock') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.minimum_stock_alert'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'reports.product.minimum-stock-alert' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('reports.product.minimum-stock-alert') }}">{{ __('Minimum Stock Alert') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.cash_flow'))
                                                                <li class="dash-item {{ request()->is('reports-monthly-cashflow') || request()->is('reports-quarterly-cashflow') ? 'active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('report.monthly.cashflow') }}">{{ __('Cash Flow') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.transaction'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'transaction.index' || Request::route()->getName() == 'transfer.create' || Request::route()->getName() == 'transaction.edit' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('transaction.index') }}">{{ __('Transaction') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.income_summary'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'report.income.summary' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('report.income.summary') }}">{{ __('Income Summary') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.expense_summary'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'report.expense.summary' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('report.expense.summary') }}">{{ __('Expense Summary') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.income_vs_expense'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'report.income.vs.expense.summary' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('report.income.vs.expense.summary') }}">{{ __('Income VS Expense') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.tax_summary'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'report.tax.summary' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('report.tax.summary') }}">{{ __('Tax Summary') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.credit_note'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'reports.credit.note' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('reports.credit.note') }}">{{ __('Credit Note Report') }}</a>
                                                                </li>
                                                            @endif
                                                            @if (menu_can('menu.dashboard.accounting.reports.management.debit_note'))
                                                                <li class="dash-item {{ Request::route()->getName() == 'reports.debit.note' ? ' active' : '' }}">
                                                                    <a class="dash-link" href="{{ route('reports.debit.note') }}">{{ __('Debit Note Report') }}</a>
                                                                </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                    @endif

                                                    {{-- Analytical (Excel-based) --}}
                                                    @if (menu_can_node('menu.dashboard.accounting.reports.analytical'))
                                                    <li class="dash-item dash-hasmenu">
                                                        <a class="dash-link" href="#">{{ __('Analytical Reports') }}
                                                            <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
                                                        </a>
                                                        <ul class="dash-submenu">
                                                            @if (menu_can('menu.dashboard.accounting.reports.analytical.powerblox'))
                                                            <li class="dash-item {{ Request::route()->getName() == 'reports.analytical.powerblox' ? ' active' : '' }}">
                                                                <a class="dash-link" href="{{ route('reports.analytical.powerblox') }}">{{ __('PowerBlox - Management Pack') }}</a>
                                                            </li>
                                                            @endif
                                                        </ul>
                                                    </li>
                                                    @endif

                                                    @if (menu_can('menu.dashboard.accounting.reports.ledger_summary'))
                                                        <li
                                                            class="dash-item {{ Request::route()->getName() == 'report.ledger' ? ' active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.ledger', 0) }}">{{ __('Ledger Summary') }}</a>
                                                        </li>
                                                    @endif
                                                    @if (menu_can('menu.dashboard.accounting.reports.balance_sheet'))
                                                        <li
                                                            class="dash-item {{ Request::route()->getName() == 'report.balance.sheet' ? ' active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.balance.sheet') }}">{{ __('Balance Sheet') }}</a>
                                                        </li>
                                                    @endif
                                                    @if (menu_can('menu.dashboard.accounting.reports.profit_loss'))
                                                        <li
                                                            class="dash-item {{ Request::route()->getName() == 'report.profit.loss' ? ' active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.profit.loss') }}">{{ __('Profit & Loss') }}</a>
                                                        </li>
                                                    @endif
                                                    @if (menu_can('menu.dashboard.accounting.reports.income_statement_comparative'))
                                                        <li
                                                            class="dash-item {{ Request::route()->getName() == 'report.dre.comparativa' ? ' active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.dre.comparativa') }}">{{ __('Income statement (comparative)') }}</a>
                                                        </li>
                                                    @endif
                                                    @if (menu_can('menu.dashboard.accounting.reports.trial_balance'))
                                                        <li
                                                            class="dash-item {{ Request::route()->getName() == 'trial.balance' || (Request::route()->getName() == 'trial-balance-report') ? ' active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('trial.balance') }}">{{ __('Trial Balance') }}</a>
                                                        </li>
                                                    @endif
                                                </ul>
                                            </li>
                                        @endif
                                    </ul>
                                </li>
                            @endif

                            @if (menu_can_node('menu.dashboard.product_system'))
                                <li class="dash-item dash-hasmenu {{ Request::is('reports/product-system*') ? ' active dash-trigger' : '' }}">
                                    <a class="dash-link" href="#">{{ __('Product system') }}<span
                                            class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                    <ul class="dash-submenu">
                                        @if (menu_can('menu.dashboard.product_system.overview'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.index' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.index') }}">{{ __('Overview') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.store_check'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.store-check' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.store-check') }}">{{ __('Store check') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.warehouse_stock'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.warehouse-stock' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.warehouse-stock') }}">{{ __('Warehouse stock') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.total_stock_value'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.total-stock-value' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.total-stock-value') }}">{{ __('Total stock value') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.high_rotation'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.high-rotation' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.high-rotation') }}">{{ __('High-value stock movements') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.out_of_stock'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.out-of-stock' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.out-of-stock') }}">{{ __('Out-of-stock products') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.minimum_stock_alert'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.minimum-stock-alert' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.minimum-stock-alert') }}">{{ __('Minimum Stock Alert') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.near_expiry'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.near-expiry' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.near-expiry') }}">{{ __('Products near expiry') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.expired_products'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.expired-products' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.expired-products') }}">{{ __('Expired products') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.transactions'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.transactions' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.transactions') }}">{{ __('Transactions') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.last_supplies'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.last-supplies' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.last-supplies') }}">{{ __('Last 5 supplies') }}</a>
                                        </li>
                                        @endif
                                        @if (menu_can('menu.dashboard.product_system.location_check'))
                                        <li class="dash-item {{ Request::route()->getName() == 'reports.product.location-check' ? ' active' : '' }}">
                                            <a class="dash-link" href="{{ route('reports.product.location-check') }}">{{ __('Row & column check') }}</a>
                                        </li>
                                        @endif
                                    </ul>
                                </li>
                            @endif

                            @if ($userPlan->hrm == 1 && menu_can_node('menu.dashboard.hrm'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'hrm-dashboard' || Request::segment(1) == 'reports-payroll' || Request::segment(1) == 'reports-hrm-tax' || Request::segment(1) == 'report-leave' || Request::segment(1) == 'reports-monthly-attendance' ? ' active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('HRM ') }}<span class="dash-arrow"><i
                                                    data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.dashboard.hrm.overview'))
                                            <li
                                                class="dash-item {{ \Request::route()->getName() == 'hrm.dashboard' ? ' active' : '' }}">
                                                <a class="dash-link"
                                                    href="{{ route('hrm.dashboard') }}">{{ __(' Overview') }}</a>
                                            </li>
                                            @endif
                                            @if (menu_can_node('menu.dashboard.hrm.reports'))
                                                <li class="dash-item dash-hasmenu
                                                                    {{ Request::segment(1) == 'reports-monthly-attendance' ||
                                                                    Request::segment(1) == 'report-leave' ||
                                                                    Request::segment(1) == 'reports-payroll' ||
                                                                    Request::segment(1) == 'reports-hrm-tax'
                                                                        ? 'active dash-trigger'
                                                                        : '' }}"
                                                    href="#hr-report" data-toggle="collapse" role="button"
                                                    aria-expanded="{{ Request::segment(1) == 'reports-monthly-attendance' || Request::segment(1) == 'report-leave' || Request::segment(1) == 'reports-payroll' || Request::segment(1) == 'reports-hrm-tax' ? 'true' : 'false' }}">
                                                    <a class="dash-link" href="#">{{ __('Reports') }}<span
                                                            class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                                    <ul class="dash-submenu">
                                                        @if (menu_can('menu.dashboard.hrm.reports.payroll'))
                                                        <li
                                                            class="dash-item {{ request()->is('reports-payroll') ? 'active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.payroll') }}">{{ __('Payroll') }}</a>
                                                        </li>
                                                        @endif
                                                        @if (menu_can('menu.dashboard.hrm.reports.leave'))
                                                        <li
                                                            class="dash-item {{ request()->is('report-leave') ? 'active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.leave') }}">{{ __('Leave') }}</a>
                                                        </li>
                                                        @endif
                                                        @if (menu_can('menu.dashboard.hrm.reports.monthly_attendance'))
                                                        <li
                                                            class="dash-item {{ request()->is('reports-monthly-attendance') ? 'active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.monthly.attendance') }}">{{ __('Monthly Attendance') }}</a>
                                                        </li>
                                                        @endif
                                                        @if (menu_can('menu.dashboard.hrm.reports.tax'))
                                                        <li
                                                            class="dash-item {{ request()->is('reports-hrm-tax') ? 'active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.hrm.tax') }}">{{ __('Tax report') }}</a>
                                                        </li>
                                                        @endif
                                                    </ul>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                            @endif

                            @if ($userPlan->crm == 1 && menu_can_node('menu.dashboard.crm'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'crm-dashboard' || Request::segment(1) == 'reports-lead' || Request::segment(1) == 'reports-deal' ? ' active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('CRM') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.dashboard.crm.overview'))
                                            <li
                                                class="dash-item {{ \Request::route()->getName() == 'crm.dashboard' ? ' active' : '' }}">
                                                <a class="dash-link"
                                                    href="{{ route('crm.dashboard') }}">{{ __(' Overview') }}</a>
                                            </li>
                                            @endif
                                            @if (menu_can_node('menu.dashboard.crm.reports'))
                                            <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'reports-lead' || Request::segment(1) == 'reports-deal' ? 'active dash-trigger' : '' }}"
                                                href="#crm-report" data-toggle="collapse" role="button"
                                                aria-expanded="{{ Request::segment(1) == 'reports-lead' || Request::segment(1) == 'reports-deal' ? 'true' : 'false' }}">
                                                <a class="dash-link" href="#">{{ __('Reports') }}<span
                                                        class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                                <ul class="dash-submenu">
                                                    @if (menu_can('menu.dashboard.crm.reports.lead'))
                                                    <li
                                                        class="dash-item {{ request()->is('reports-lead') ? 'active' : '' }}">
                                                        <a class="dash-link"
                                                            href="{{ route('report.lead') }}">{{ __('Lead') }}</a>
                                                    </li>
                                                    @endif
                                                    @if (menu_can('menu.dashboard.crm.reports.deal'))
                                                    <li
                                                        class="dash-item {{ request()->is('reports-deal') ? 'active' : '' }}">
                                                        <a class="dash-link"
                                                            href="{{ route('report.deal') }}">{{ __('Deal') }}</a>
                                                    </li>
                                                    @endif
                                                </ul>
                                            </li>
                                            @endif
                                        </ul>
                                    </li>
                            @endif

                            @if (menu_can('menu.dashboard.sales'))
                                <li class="dash-item {{ \Request::route()->getName() == 'sales.dashboard' ? ' active' : '' }}">
                                    <a class="dash-link" href="{{ route('sales.dashboard') }}">{{ __('Sales Users & Clients') }}</a>
                                </li>
                            @endif

                            @if ($userPlan->project == 1 && menu_can('menu.dashboard.project'))
                                    <li
                                        class="dash-item {{ Request::route()->getName() == 'project.dashboard' ? ' active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('project.dashboard') }}">{{ __('Project ') }}</a>
                                    </li>
                            @endif

                            @if ($userPlan->pos == 1 && menu_can_node('menu.dashboard.pos'))
                                    <li
                                        class="dash-item dash-hasmenu {{
                                        Request::segment(1) == 'pos-dashboard' ||
                                        Request::segment(1) == 'reports-warehouse' ||
                                        Request::segment(1) == 'reports-daily-purchase' ||
                                        Request::segment(1) == 'reports-monthly-purchase' ||
                                        Request::segment(1) == 'reports-daily-pos' ||
                                        Request::segment(1) == 'reports-monthly-pos' ||
                                        Request::segment(1) == 'reports-pos-vs-purchase' ? ' active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('POS') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.dashboard.pos.overview'))
                                            <li
                                                class="dash-item {{ \Request::route()->getName() == 'pos.dashboard' ? ' active' : '' }}">
                                                <a class="dash-link"
                                                    href="{{ route('pos.dashboard') }}">{{ __(' Overview') }}</a>
                                            </li>
                                            @endif
                                            @if (menu_can_node('menu.dashboard.pos.reports'))
                                                <li class="dash-item dash-hasmenu {{
                                                    Request::segment(1) == 'reports-warehouse' ||
                                                    Request::segment(1) == 'reports-daily-purchase' ||
                                                    Request::segment(1) == 'reports-monthly-purchase' ||
                                                    Request::segment(1) == 'reports-daily-pos' ||
                                                    Request::segment(1) == 'reports-monthly-pos' ||
                                                    Request::segment(1) == 'reports-pos-vs-purchase' ? 'active dash-trigger' : '' }}"
                                                    href="#crm-report" data-toggle="collapse" role="button"
                                                    aria-expanded="{{
                                                    Request::segment(1) == 'reports-warehouse' ||
                                                    Request::segment(1) == 'reports-daily-purchase' ||
                                                    Request::segment(1) == 'reports-monthly-purchase' ||
                                                    Request::segment(1) == 'reports-daily-pos' ||
                                                    Request::segment(1) == 'reports-monthly-pos' ||
                                                    Request::segment(1) == 'reports-pos-vs-purchase' ? 'true' : 'false' }}">
                                                    <a class="dash-link" href="#">{{ __('Reports') }}<span
                                                            class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                                    <ul class="dash-submenu">
                                                        @if (menu_can('menu.dashboard.pos.reports.warehouse'))
                                                        <li
                                                            class="dash-item {{ request()->is('reports-warehouse') ? 'active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.warehouse') }}">{{ __('Warehouse Report') }}</a>
                                                        </li>
                                                        @endif
                                                        @if (menu_can('menu.dashboard.pos.reports.purchase_daily'))
                                                        <li
                                                            class="dash-item {{ request()->is('reports-daily-purchase') || request()->is('reports-monthly-purchase') ? 'active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.daily.purchase') }}">{{ __('Purchase Daily/Monthly Report') }}</a>
                                                        </li>
                                                        @endif
                                                        @if (menu_can('menu.dashboard.pos.reports.pos_daily'))
                                                        <li
                                                            class="dash-item {{ request()->is('reports-daily-pos') || request()->is('reports-monthly-pos') ? 'active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.daily.pos') }}">{{ __('POS Daily/Monthly Report') }}</a>
                                                        </li>
                                                        @endif
                                                        @if (menu_can('menu.dashboard.pos.reports.pos_vs_purchase'))
                                                        <li
                                                            class="dash-item {{ request()->is('reports-pos-vs-purchase') ? 'active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.pos.vs.purchase') }}">{{ __('Pos VS Purchase Report') }}</a>
                                                        </li>
                                                        @endif
                                                        @if (menu_can('menu.dashboard.pos.reports.pos_by_branch'))
                                                        <li
                                                            class="dash-item {{ request()->is('reports-pos-by-branch') ? 'active' : '' }}">
                                                            <a class="dash-link"
                                                                href="{{ route('report.pos.branch') }}">{{ __('POS by Branch') }}</a>
                                                        </li>
                                                        @endif
                                                    </ul>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                            @endif

                        </ul>
                    </li>
                @endif
                <!--------------------- End Dashboard ----------------------------------->


                <!--------------------- Start HRM ----------------------------------->

                @if (!empty($userPlan) && $userPlan->hrm == 1)
                    @if (menu_can_node('menu.hrm_system'))

                        <li
                            class="dash-item dash-hasmenu {{ Request::segment(1) == 'holiday-calender' ||
                            Request::segment(1) == 'leavetype' || Request::segment(1) == 'leave' ||
                            Request::segment(1) == 'attendanceemployee' || Request::segment(1) == 'bulkattendance' ||
                            Request::segment(1) == 'indicator' || Request::segment(1) == 'appraisal' ||
                            Request::segment(1) == 'goaltracking' || Request::segment(1) == 'trainer' ||
                            Request::segment(1) == 'event' || Request::segment(1) == 'meeting' ||
                            Request::segment(1) == 'account-assets' || Request::segment(1) == 'leavetype' ||
                            Request::segment(1) == 'meeting-calender' || Request::segment(1) == 'document-upload' ||
                            Request::segment(1) == 'document' || Request::segment(1) == 'performanceType' ||
                            Request::segment(1) == 'department' ||
                            Request::segment(1) == 'designation' || Request::segment(1) == 'employee' ||
                            Request::segment(1) == 'leave_requests' || Request::segment(1) == 'holidays' ||
                            Request::segment(1) == 'policies' || Request::segment(1) == 'leave_calender' ||
                            Request::segment(1) == 'award' || Request::segment(1) == 'transfer' ||
                            Request::segment(1) == 'resignation' || Request::segment(1) == 'training' ||
                            Request::segment(1) == 'travel' || Request::segment(1) == 'promotion' ||
                            Request::segment(1) == 'complaint' || Request::segment(1) == 'warning' ||
                            Request::segment(1) == 'termination' || Request::segment(1) == 'announcement' ||
                            Request::segment(1) == 'job' || Request::segment(1) == 'job-application' ||
                            Request::segment(1) == 'candidates-job-applications' || Request::segment(1) == 'job-onboard' ||
                            Request::segment(1) == 'custom-question' || Request::segment(1) == 'interview-schedule' ||
                            Request::segment(1) == 'career' || Request::segment(1) == 'holiday' ||
                            Request::segment(1) == 'setsalary' || Request::segment(1) == 'payslip' ||
                            Request::segment(1) == 'paysliptype' || Request::segment(1) == 'company-policy' ||
                            Request::segment(1) == 'job-stage' || Request::segment(1) == 'job-category' ||
                            Request::segment(1) == 'terminationtype' || Request::segment(1) == 'awardtype' ||
                            Request::segment(1) == 'trainingtype' || Request::segment(1) == 'goaltype' ||
                            Request::segment(1) == 'allowanceoption' || Request::segment(1) == 'competencies' ||
                            Request::segment(1) == 'loanoption' || Request::segment(1) == 'deductionoption'
                                ? 'active dash-trigger'
                                : '' }}">
                            <a href="#!" class="dash-link ">
                                <span class="dash-micon">
                                    <i class="ti ti-user"></i>
                                </span>
                                <span class="dash-mtext">
                                    {{ __('HRM System') }}
                                </span>
                                <span class="dash-arrow">
                                    <i data-feather="chevron-right"></i>
                                </span>
                            </a>
                            <ul class="dash-submenu">
                                @if (menu_can('menu.hrm_system.employee'))
                                    <li
                                        class="dash-item  {{ Request::segment(1) == 'employee' ? 'active dash-trigger' : '' }}   ">
                                        @if (\Auth::user()->type == 'Employee')
                                            @php
                                                $employee = App\Models\Employee::where(
                                                    'user_id',
                                                    \Auth::user()->id,
                                                )->first();
                                            @endphp
                                            <a class="dash-link"
                                                href="{{ route('employee.show', \Illuminate\Support\Facades\Crypt::encrypt($employee->id)) }}">{{ __('Employee') }}</a>
                                        @else
                                            <a href="{{ route('employee.index') }}" class="dash-link">
                                                {{ __('Employee Setup') }}
                                            </a>
                                        @endif
                                    </li>
                                @endif

                                @if (menu_can_node('menu.hrm_system.payroll'))
                                    <li
                                        class="dash-item dash-hasmenu  {{ Request::segment(1) == 'setsalary' || Request::segment(1) == 'payslip' ? 'active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('Payroll Setup') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.hrm_system.payroll.set_salary'))
                                                <li class="dash-item {{ request()->is('setsalary*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('setsalary.index') }}">{{ __('Set salary') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.payroll.payslip'))
                                                <li class="dash-item {{ request()->is('payslip*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('payslip.index') }}">{{ __('Payslip') }}</a>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                                @endif

                                @if (menu_can_node('menu.hrm_system.leave'))
                                    <li
                                        class="dash-item dash-hasmenu  {{ Request::segment(1) == 'leave' || Request::segment(1) == 'attendanceemployee' ? 'active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('Leave Management Setup') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.hrm_system.leave.manage'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'leave.index' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('leave.index') }}">{{ __('Manage Leave') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can_node('menu.hrm_system.leave.attendance'))
                                                <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'attendanceemployee' ? 'active dash-trigger' : '' }}"
                                                    href="#navbar-attendance" data-toggle="collapse" role="button"
                                                    aria-expanded="{{ Request::segment(1) == 'attendanceemployee' ? 'true' : 'false' }}">
                                                    <a class="dash-link" href="#">{{ __('Attendance') }}<span
                                                            class="dash-arrow"><i
                                                                data-feather="chevron-right"></i></span></a>
                                                    <ul class="dash-submenu">
                                                        @if (menu_can('menu.hrm_system.leave.attendance.mark'))
                                                            <li
                                                                class="dash-item {{ Request::route()->getName() == 'attendanceemployee.index' ? 'active' : '' }}">
                                                                <a class="dash-link"
                                                                    href="{{ route('attendanceemployee.index') }}">{{ __('Mark Attendance') }}</a>
                                                            </li>
                                                        @endif
                                                        @if (menu_can('menu.hrm_system.leave.attendance.bulk'))
                                                            <li
                                                                class="dash-item {{ Request::route()->getName() == 'attendanceemployee.bulkattendance' ? 'active' : '' }}">
                                                                <a class="dash-link"
                                                                    href="{{ route('attendanceemployee.bulkattendance') }}">{{ __('Bulk Attendance') }}</a>
                                                            </li>
                                                        @endif
                                                    </ul>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                                @endif

                                @if (menu_can_node('menu.hrm_system.performance'))
                                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'indicator' || Request::segment(1) == 'appraisal' || Request::segment(1) == 'goaltracking' ? 'active dash-trigger' : '' }}"
                                        href="#navbar-performance" data-toggle="collapse" role="button"
                                        aria-expanded="{{ Request::segment(1) == 'indicator' || Request::segment(1) == 'appraisal' || Request::segment(1) == 'goaltracking' ? 'true' : 'false' }}">
                                        <a class="dash-link" href="#">{{ __('Performance Setup') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul
                                            class="dash-submenu {{ Request::segment(1) == 'indicator' || Request::segment(1) == 'appraisal' || Request::segment(1) == 'goaltracking' ? 'show' : 'collapse' }}">
                                            @if (menu_can('menu.hrm_system.performance.indicator'))
                                                <li class="dash-item {{ request()->is('indicator*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('indicator.index') }}">{{ __('Indicator') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.performance.appraisal'))
                                                <li class="dash-item {{ request()->is('appraisal*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('appraisal.index') }}">{{ __('Appraisal') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.performance.goal_tracking'))
                                                <li
                                                    class="dash-item  {{ request()->is('goaltracking*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('goaltracking.index') }}">{{ __('Goal Tracking') }}</a>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                                @endif

                                @if (menu_can_node('menu.hrm_system.training'))
                                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'trainer' || Request::segment(1) == 'training' ? 'active dash-trigger' : '' }}"
                                        href="#navbar-training" data-toggle="collapse" role="button"
                                        aria-expanded="{{ Request::segment(1) == 'trainer' || Request::segment(1) == 'training' ? 'true' : 'false' }}">
                                        <a class="dash-link" href="#">{{ __('Training Setup') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.hrm_system.training.list'))
                                                <li class="dash-item {{ request()->is('training*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('training.index') }}">{{ __('Training List') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.training.trainer'))
                                                <li class="dash-item {{ request()->is('trainer*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('trainer.index') }}">{{ __('Trainer') }}</a>
                                                </li>
                                            @endif

                                        </ul>
                                    </li>
                                @endif

                                @if (menu_can_node('menu.hrm_system.recruitment'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'job' || Request::segment(1) == 'job-application' || Request::segment(1) == 'candidates-job-applications' || Request::segment(1) == 'job-onboard' || Request::segment(1) == 'custom-question' || Request::segment(1) == 'interview-schedule' || Request::segment(1) == 'career' ? 'active dash-trigger' : '' }}    ">
                                        <a class="dash-link" href="#">{{ __('Recruitment Setup') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.hrm_system.recruitment.jobs'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'job.index' || Request::route()->getName() == 'job.create' || Request::route()->getName() == 'job.edit' || Request::route()->getName() == 'job.show' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('job.index') }}">{{ __('Jobs') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.recruitment.job_create'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'job.create' ? 'active' : '' }} ">
                                                    <a class="dash-link"
                                                        href="{{ route('job.create') }}">{{ __('Job Create') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.recruitment.job_application'))
                                                <li
                                                    class="dash-item {{ request()->is('job-application*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('job-application.index') }}">{{ __('Job Application') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.recruitment.job_candidate'))
                                                <li
                                                    class="dash-item {{ request()->is('candidates-job-applications') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('job.application.candidate') }}">{{ __('Job Candidate') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.recruitment.job_onboard'))
                                                <li
                                                    class="dash-item {{ request()->is('job-onboard*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('job.on.board') }}">{{ __('Job On-boarding') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.recruitment.custom_question'))
                                                <li
                                                    class="dash-item  {{ request()->is('custom-question*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('custom-question.index') }}">{{ __('Custom Question') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.recruitment.interview_schedule'))
                                                <li
                                                    class="dash-item {{ request()->is('interview-schedule*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('interview-schedule.index') }}">{{ __('Interview Schedule') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.recruitment.career'))
                                                <li class="dash-item {{ request()->is('career*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('career', [\Auth::user()->creatorId(), $lang]) }}">{{ __('Career') }}</a>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                                @endif

                                @if (menu_can_node('menu.hrm_system.hr_admin'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'holiday-calender' || Request::segment(1) == 'holiday' || Request::segment(1) == 'policies' || Request::segment(1) == 'award' || Request::segment(1) == 'transfer' || Request::segment(1) == 'resignation' || Request::segment(1) == 'travel' || Request::segment(1) == 'promotion' || Request::segment(1) == 'complaint' || Request::segment(1) == 'warning' || Request::segment(1) == 'termination' || Request::segment(1) == 'announcement' || Request::segment(1) == 'competencies' ? 'active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('HR Admin Setup') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.hrm_system.hr_admin.award'))
                                                <li class="dash-item {{ request()->is('award*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('award.index') }}">{{ __('Award') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.hr_admin.transfer'))
                                                <li class="dash-item  {{ request()->is('transfer*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('transfer.index') }}">{{ __('Transfer') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.hr_admin.resignation'))
                                                <li
                                                    class="dash-item {{ request()->is('resignation*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('resignation.index') }}">{{ __('Resignation') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.hr_admin.travel'))
                                                <li class="dash-item {{ request()->is('travel*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('travel.index') }}">{{ __('Trip') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.hr_admin.promotion'))
                                                <li class="dash-item {{ request()->is('promotion*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('promotion.index') }}">{{ __('Promotion') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.hr_admin.complaint'))
                                                <li class="dash-item {{ request()->is('complaint*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('complaint.index') }}">{{ __('Complaints') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.hr_admin.warning'))
                                                <li class="dash-item {{ request()->is('warning*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('warning.index') }}">{{ __('Warning') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.hr_admin.termination'))
                                                <li
                                                    class="dash-item {{ request()->is('termination*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('termination.index') }}">{{ __('Termination') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.hr_admin.announcement'))
                                                <li
                                                    class="dash-item {{ request()->is('announcement*') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('announcement.index') }}">{{ __('Announcement') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.hrm_system.hr_admin.holiday'))
                                                <li
                                                    class="dash-item {{ request()->is('holiday*') || request()->is('holiday-calender') ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('holiday.index') }}">{{ __('Holidays') }}</a>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                                @endif

                                @if (menu_can('menu.hrm_system.event'))
                                    <li class="dash-item {{ request()->is('event*') ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('event.index') }}">{{ __('Event Setup') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.hrm_system.meeting'))
                                    <li class="dash-item {{ request()->is('meeting*') ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('meeting.index') }}">{{ __('Meeting') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.hrm_system.assets'))
                                    <li class="dash-item {{ request()->is('account-assets*') ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('account-assets.index') }}">{{ __('Employees Asset Setup ') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.hrm_system.document'))
                                    <li class="dash-item {{ request()->is('document-upload*') ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('document-upload.index') }}">{{ __('Document Setup') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.hrm_system.company_policy'))
                                    <li class="dash-item {{ request()->is('company-policy*') ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('company-policy.index') }}">{{ __('Company policy') }}</a>
                                    </li>
                                @endif
                                @if ((\Auth::user()->type == 'company' || \Auth::user()->type == 'HR') && menu_can('menu.hrm_system.branch'))
                                    <li
                                        class="dash-item {{ Request::segment(1) == 'branch'
                                            ? 'active dash-trigger'
                                            : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('branch.index') }}">{{ __('Branch') }}</a>
                                    </li>
                                @endif


                            </ul>
                        </li>
                    @endif
                @endif

                <!--------------------- End HRM ----------------------------------->

                <!--------------------- Start Account ----------------------------------->

                @if (!empty($userPlan) && $userPlan->account == 1)
                    @if (menu_can_node('menu.accounting_system'))
                        <li
                            class="dash-item dash-hasmenu
                                        {{ Request::route()->getName() == 'print-setting' ||
                                        Request::segment(1) == 'customer' || Request::segment(1) == 'vender' ||
                                        Request::segment(1) == 'proposal' || Request::segment(1) == 'bank-account' ||
                                        Request::segment(1) == 'bank-transfer' || Request::segment(1) == 'bank-reconciliation' || Request::segment(1) == 'invoice' ||
                                        Request::segment(1) == 'revenue' || Request::segment(1) == 'credit-note' ||
                                        Request::segment(1) == 'taxes' || Request::segment(1) == 'product-category' ||
                                        Request::segment(1) == 'product-unit' || Request::segment(1) == 'payment-method' ||
                                        Request::segment(1) == 'custom-field' || Request::segment(1) == 'chart-of-account-type' ||
                                        (Request::segment(1) == 'transaction' && Request::segment(2) != 'ledger' &&
                                            Request::segment(2) != 'balance-sheet-report' && Request::segment(2) != 'trial-balance') ||
                                        Request::segment(1) == 'goal' || Request::segment(1) == 'budget' ||
                                        Request::segment(1) == 'chart-of-account' || Request::segment(1) == 'journal-entry' ||
                                        Request::segment(1) == 'fiscal-year-closing' ||
                                        Request::segment(2) == 'ledger' || Request::segment(2) == 'balance-sheet' ||
                                        Request::segment(2) == 'trial-balance' || Request::segment(2) == 'profit-loss' ||
                                        Request::segment(1) == 'bill' || Request::segment(1) == 'expense' ||
                                        Request::segment(1) == 'payment' || Request::segment(1) == 'debit-note' ||
                                        Request::segment(1) == 'quotation' || Request::segment(1) == 'proforma' ||
                                        Request::segment(1) == 'purchase' || Request::segment(1) == 'vendor-quote' ? ' active dash-trigger'
                                            : '' }}">
                            <a href="#!" class="dash-link"><span class="dash-micon"><i
                                        class="ti ti-box"></i></span><span
                                    class="dash-mtext">{{ __('Accounting System ') }}
                                </span><span class="dash-arrow"><i data-feather="chevron-right"></i></span>
                            </a>
                            <ul class="dash-submenu">

                                @if (menu_can_node('menu.accounting_system.banking'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'bank-account' || Request::segment(1) == 'bank-transfer' || Request::segment(1) == 'bank-reconciliation' ? 'active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('Banking') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.accounting_system.banking.account'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'bank-account.index' || Request::route()->getName() == 'bank-account.create' || Request::route()->getName() == 'bank-account.edit' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('bank-account.index') }}">{{ __('Account') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.banking.transfer'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'bank-transfer.index' || Request::route()->getName() == 'bank-transfer.create' || Request::route()->getName() == 'bank-transfer.edit' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('bank-transfer.index') }}">{{ __('Transfer') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.banking.reconciliation'))
                                                <li
                                                    class="dash-item {{ str_starts_with(Request::route()->getName() ?? '', 'bank-reconciliation') ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('bank-reconciliation.index') }}">{{ __('Bank reconciliation') }}</a>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                                @endif
                                @if (menu_can_node('menu.accounting_system.sales'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'customer' || Request::segment(1) == 'proposal' || Request::segment(1) == 'invoice' || Request::segment(1) == 'quotation' || Request::segment(1) == 'proforma' || Request::segment(1) == 'revenue' || Request::segment(1) == 'credit-note' ? 'active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('Sales') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.accounting_system.sales.customer'))
                                                <li
                                                    class="dash-item {{ Request::segment(1) == 'customer' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('customer.index') }}">{{ __('Customer') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.sales.estimate'))
                                                <li
                                                    class="dash-item {{ Request::segment(1) == 'proposal' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('proposal.index') }}">{{ __('Estimate') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.sales.invoice'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'invoice.index' || Request::route()->getName() == 'invoice.create' || Request::route()->getName() == 'invoice.edit' || Request::route()->getName() == 'invoice.show' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('invoice.index') }}">{{ __('Invoice') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.sales.quotation'))
                                                <li
                                                    class="dash-item {{ Request::segment(1) == 'quotation' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('quotation.index') }}">{{ __('Quotation') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.sales.proforma'))
                                                <li
                                                    class="dash-item {{ Request::segment(1) == 'proforma' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('proforma.index') }}">{{ __('Proforma Invoice') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.sales.revenue'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'revenue.index' || Request::route()->getName() == 'revenue.create' || Request::route()->getName() == 'revenue.edit' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('revenue.index') }}">{{ __('Revenue') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.sales.credit_note'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'credit.note' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('credit.note') }}">{{ __('Credit Note') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.sales.debit_note'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'sale.debit.note' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('sale.debit.note') }}">{{ __('Debit Note') }}</a>
                                                </li>
                                            @endif
                                            
                                        </ul>
                                    </li>
                                @endif
                                @if (menu_can_node('menu.accounting_system.purchases'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'bill' || Request::segment(1) == 'vender' || Request::segment(1) == 'expense' || Request::segment(1) == 'payment' || Request::segment(1) == 'debit-note' || Request::segment(1) == 'purchase' || Request::segment(1) == 'vendor-quote' ? 'active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('Purchases') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.accounting_system.purchases.vender'))
                                                <li
                                                    class="dash-item {{ Request::segment(1) == 'vender' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('vender.index') }}">{{ __('Suppiler') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.purchases.vendor_quote'))
                                                <li
                                                    class="dash-item {{ Request::segment(1) == 'vendor-quote' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('vendor-quote.index') }}">{{ __('Vendor Quote') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.purchases.purchase'))
                                                <li
                                                    class="dash-item {{ Request::segment(1) == 'purchase' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('purchase.index') }}">{{ __('Purchase Order') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.purchases.bill'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'bill.index' || Request::route()->getName() == 'bill.create' || Request::route()->getName() == 'bill.edit' || Request::route()->getName() == 'bill.show' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                    href="{{ route('bill.index') }}">{{ __('Bill') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.purchases.expense'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'expense.index' || Request::route()->getName() == 'expense.create' || Request::route()->getName() == 'expense.edit' || Request::route()->getName() == 'expense.show' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('expense.index') }}">{{ __('Expense') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.purchases.payment'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'payment.index' || Request::route()->getName() == 'payment.create' || Request::route()->getName() == 'payment.edit' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('payment.index') }}">{{ __('Payment') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.purchases.debit_note'))
                                                <li
                                                    class="dash-item  {{ Request::route()->getName() == 'debit.note' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('debit.note') }}">{{ __('Debit Note') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.purchases.credit_note'))
                                                <li
                                                    class="dash-item  {{ Request::route()->getName() == 'bill.credit.note' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('bill.credit.note') }}">{{ __('Credit Note') }}</a>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                                @endif
                                @if (menu_can_node('menu.accounting_system.double_entry'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'chart-of-account' ||
                                        Request::segment(1) == 'journal-entry' ||
                                        Request::segment(1) == 'fiscal-year-closing'
                                            ? 'active dash-trigger'
                                            : '' }}">
                                        <a class="dash-link" href="#">{{ __('Double Entry') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.accounting_system.double_entry.chart_of_account'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'chart-of-account.index' || Request::route()->getName() == 'chart-of-account.show' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('chart-of-account.index') }}">{{ __('Chart of Accounts') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.double_entry.journal_entry'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'journal-entry.edit' ||
                                                    Request::route()->getName() == 'journal-entry.create' ||
                                                    Request::route()->getName() == 'journal-entry.index' ||
                                                    Request::route()->getName() == 'journal-entry.show'
                                                        ? ' active'
                                                        : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('journal-entry.index') }}">{{ __('Journal Account') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.accounting_system.double_entry.fiscal_year_closing'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'fiscal-year-closing.index' ? ' active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('fiscal-year-closing.index') }}">{{ __('Fiscal year closing') }}</a>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                                @endif
                                @if (\Auth::user()->type == 'company' && menu_can('menu.accounting_system.budget'))
                                    <li class="dash-item {{ Request::segment(1) == 'budget' ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('budget.index') }}">{{ __('Budget Planner') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.accounting_system.goal'))
                                    <li class="dash-item {{ Request::segment(1) == 'goal' ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('goal.index') }}">{{ __('Financial Goal') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.accounting_system.setup'))
                                    <li
                                        class="dash-item {{ Request::segment(1) == 'taxes' || Request::segment(1) == 'product-category' || Request::segment(1) == 'product-unit' || Request::segment(1) == 'payment-method' || Request::segment(1) == 'custom-field' || Request::segment(1) == 'chart-of-account-type' ? 'active dash-trigger' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('taxes.index') }}">{{ __('Accounting Setup') }}</a>
                                    </li>
                                @endif

                                @if (menu_can('menu.accounting_system.print_settings'))
                                    <li
                                        class="dash-item {{ Request::route()->getName() == 'print-setting' ? ' active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('print.setting') }}">{{ __('Print Settings') }}</a>
                                    </li>
                                @endif

                            </ul>
                        </li>
                    @endif
                @endif

                <!--------------------- End Account ----------------------------------->

                <!--------------------- Start CRM ----------------------------------->

                @if (!empty($userPlan) && $userPlan->crm == 1)
                    @if (menu_can_node('menu.crm_system'))
                        <li
                            class="dash-item dash-hasmenu {{ Request::segment(1) == 'stages' || Request::segment(1) == 'labels' || Request::segment(1) == 'sources' || Request::segment(1) == 'lead_stages' || Request::segment(1) == 'pipelines' || Request::segment(1) == 'deals' || Request::segment(1) == 'leads' || Request::segment(1) == 'form_builder' || Request::segment(1) == 'contractType' || Request::segment(1) == 'form_response' || Request::segment(1) == 'contract' ? ' active dash-trigger' : '' }}">
                            <a href="#!" class="dash-link"><span class="dash-micon"><i
                                        class="ti ti-layers-difference"></i></span><span
                                    class="dash-mtext">{{ __('CRM System') }}</span><span class="dash-arrow"><i
                                        data-feather="chevron-right"></i></span></a>
                            <ul
                                class="dash-submenu {{ Request::segment(1) == 'stages' || Request::segment(1) == 'labels' || Request::segment(1) == 'sources' || Request::segment(1) == 'lead_stages' || Request::segment(1) == 'leads' || Request::segment(1) == 'form_builder' || Request::segment(1) == 'form_response' || Request::segment(1) == 'deals' || Request::segment(1) == 'pipelines' ? 'show' : '' }}">
                                @if (menu_can('menu.crm_system.leads'))
                                    <li
                                        class="dash-item {{ Request::route()->getName() == 'leads.list' || Request::route()->getName() == 'leads.index' || Request::route()->getName() == 'leads.show' ? ' active' : '' }}">
                                        <a class="dash-link" href="{{ route('leads.index') }}">{{ __('Leads') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.crm_system.deals'))
                                    <li
                                        class="dash-item {{ Request::route()->getName() == 'deals.list' || Request::route()->getName() == 'deals.index' || Request::route()->getName() == 'deals.show' ? ' active' : '' }}">
                                        <a class="dash-link" href="{{ route('deals.index') }}">{{ __('Deals') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.crm_system.form_builder'))
                                    <li
                                        class="dash-item {{ Request::segment(1) == 'form_builder' || Request::segment(1) == 'form_response' ? 'active open' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('form_builder.index') }}">{{ __('Form Builder') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.crm_system.contract'))
                                    <li
                                        class="dash-item  {{ Request::route()->getName() == 'contract.index' || Request::route()->getName() == 'contract.show' ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('contract.index') }}">{{ __('Contract') }}</a>
                                    </li>
                                @endif
                        @if (menu_can('menu.crm_system.setup'))
                            <li
                                class="dash-item  {{ Request::segment(1) == 'stages' || Request::segment(1) == 'labels' || Request::segment(1) == 'sources' || Request::segment(1) == 'lead_stages' || Request::segment(1) == 'pipelines' || Request::segment(1) == 'product-category' || Request::segment(1) == 'product-unit' || Request::segment(1) == 'contractType' || Request::segment(1) == 'payment-method' || Request::segment(1) == 'custom-field' || Request::segment(1) == 'chart-of-account-type' ? 'active dash-trigger' : '' }}">
                                <a class="dash-link"
                                    href="{{ route('pipelines.index') }}   ">{{ __('CRM System Setup') }}</a>

                            </li>
                        @endif
                            </ul>
                        </li>
                    @endif
                @endif

                <!--------------------- End CRM ----------------------------------->

                <!--------------------- Start Project ----------------------------------->

                @if (!empty($userPlan) && $userPlan->project == 1)
                    @if (menu_can_node('menu.project_system'))
                        <li
                            class="dash-item dash-hasmenu
                                                    {{ Request::segment(1) == 'project' ||
                                                    Request::segment(1) == 'bugs-report' ||
                                                    Request::segment(1) == 'bugstatus' ||
                                                    Request::segment(1) == 'project-task-stages' ||
                                                    Request::segment(1) == 'calendar' ||
                                                    Request::segment(1) == 'timesheet-list' ||
                                                    Request::segment(1) == 'taskboard' ||
                                                    Request::segment(1) == 'timesheet-list' ||
                                                    Request::segment(1) == 'taskboard' ||
                                                    Request::segment(1) == 'projects' ||
                                                    Request::segment(1) == 'project_report'
                                                        ? 'active dash-trigger'
                                                        : '' }}">
                            <a href="#!" class="dash-link"><span class="dash-micon"><i
                                        class="ti ti-share"></i></span><span
                                    class="dash-mtext">{{ __('Project System') }}</span><span class="dash-arrow"><i
                                        data-feather="chevron-right"></i></span></a>
                            <ul class="dash-submenu">
                                @if (menu_can('menu.project_system.projects'))
                                    <li
                                        class="dash-item  {{ Request::segment(1) == 'project' || Request::route()->getName() == 'projects.list' || Request::route()->getName() == 'projects.list' || Request::route()->getName() == 'projects.index' || Request::route()->getName() == 'projects.show' || request()->is('projects/*') ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('projects.index') }}">{{ __('Projects') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.project_system.tasks'))
                                    <li class="dash-item {{ request()->is('taskboard*') ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('taskBoard.view', 'list') }}">{{ __('Tasks') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.project_system.timesheet'))
                                    <li class="dash-item {{ request()->is('timesheet-list*') ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('timesheet.list') }}">{{ __('Timesheet') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.project_system.bug'))
                                    <li class="dash-item {{ request()->is('bugs-report*') ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('bugs.view', 'list') }}">{{ __('Bug') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.project_system.task_calendar'))
                                    <li class="dash-item {{ request()->is('calendar*') ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('task.calendar', ['all']) }}">{{ __('Task Calendar') }}</a>
                                    </li>
                                @endif
                                @if (\Auth::user()->type != 'super admin' && menu_can('menu.project_system.tracker'))
                                    <li class="dash-item  {{ Request::segment(1) == 'time-tracker' ? 'active open' : '' }}">
                                        <a class="dash-link" href="{{ route('time.tracker') }}">{{ __('Tracker') }}</a>
                                    </li>
                                @endif
                                @if ((\Auth::user()->type == 'company' || \Auth::user()->type == 'Employee') && menu_can('menu.project_system.project_report'))
                                    <li
                                        class="dash-item  {{ Request::route()->getName() == 'project_report.index' || Request::route()->getName() == 'project_report.show' ? 'active' : '' }}">
                                        <a class="dash-link"
                                            href="{{ route('project_report.index') }}">{{ __('Project Report') }}</a>
                                    </li>
                                @endif

                                @if (menu_can_node('menu.project_system.setup'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'bugstatus' || Request::segment(1) == 'project-task-stages' ? 'active dash-trigger' : '' }}">
                                        <a class="dash-link" href="#">{{ __('Project System Setup') }}<span
                                                class="dash-arrow"><i data-feather="chevron-right"></i></span></a>
                                        <ul class="dash-submenu">
                                            @if (menu_can('menu.project_system.setup.task_stages'))
                                                <li
                                                    class="dash-item  {{ Request::route()->getName() == 'project-task-stages.index' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('project-task-stages.index') }}">{{ __('Project Task Stages') }}</a>
                                                </li>
                                            @endif
                                            @if (menu_can('menu.project_system.setup.bug_status'))
                                                <li
                                                    class="dash-item {{ Request::route()->getName() == 'bugstatus.index' ? 'active' : '' }}">
                                                    <a class="dash-link"
                                                        href="{{ route('bugstatus.index') }}">{{ __('Bug Status') }}</a>
                                                </li>
                                            @endif
                                        </ul>
                                    </li>
                                @endif
                            </ul>
                        </li>
                    @endif
                @endif

                <!--------------------- End Project ----------------------------------->



                <!--------------------- Start User Managaement System ----------------------------------->

                @if (\Auth::user()->type != 'super admin' && menu_can_node('menu.user_management'))
                    <li
                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'users' ||
                        Request::segment(1) == 'roles' ||
                        Request::segment(1) == 'clients' ||
                        Request::segment(1) == 'userlogs'
                            ? ' active dash-trigger'
                            : '' }}">

                        <a href="#!" class="dash-link "><span class="dash-micon"><i
                                    class="ti ti-users"></i></span><span
                                class="dash-mtext">{{ __('User Management') }}</span><span class="dash-arrow"><i
                                    data-feather="chevron-right"></i></span></a>
                        <ul class="dash-submenu">
                            @if (menu_can('menu.user_management.user'))
                                <li
                                    class="dash-item {{ Request::route()->getName() == 'users.index' || Request::route()->getName() == 'users.create' || Request::route()->getName() == 'users.edit' || Request::route()->getName() == 'user.userlog' ? ' active' : '' }}">
                                    <a class="dash-link" href="{{ route('users.index') }}">{{ __('User') }}</a>
                                </li>
                            @endif
                            @if (menu_can('menu.user_management.role'))
                                <li
                                    class="dash-item {{ Request::route()->getName() == 'roles.index' || Request::route()->getName() == 'roles.create' || Request::route()->getName() == 'roles.edit' ? ' active' : '' }} ">
                                    <a class="dash-link" href="{{ route('roles.index') }}">{{ __('Role') }}</a>
                                </li>
                            @endif
                            @if (menu_can('menu.user_management.client'))
                                <li
                                    class="dash-item {{ Request::route()->getName() == 'clients.index' || Request::segment(1) == 'clients' || Request::route()->getName() == 'clients.edit' ? ' active' : '' }}">
                                    <a class="dash-link" href="{{ route('clients.index') }}">{{ __('Client') }}</a>
                                </li>
                            @endif
                        </ul>
                    </li>
                @endif

                <!--------------------- End User Managaement System----------------------------------->


                <!--------------------- Start Products System ----------------------------------->
                {{-- Show only when plan includes Stock or POS (POS package includes stock) --}}
                @if (!empty($userPlan) && (($userPlan->stock ?? 0) == 1 || $userPlan->pos == 1) && menu_can_node('menu.products_system'))
                    <li class="dash-item dash-hasmenu">
                        <a href="#!" class="dash-link ">
                            <span class="dash-micon"><i class="ti ti-shopping-cart"></i></span><span
                                class="dash-mtext">{{ __('Products System') }}</span><span class="dash-arrow">
                                <i data-feather="chevron-right"></i></span>
                        </a>
                        <ul class="dash-submenu">
                            @if (menu_can('menu.products_system.warehouse'))
                                <li
                                    class="dash-item {{ Request::route()->getName() == 'warehouse.index' || Request::route()->getName() == 'warehouse.show' ? ' active' : '' }}">
                                    <a class="dash-link" href="{{ route('warehouse.index') }}">{{ __('Warehouse') }}</a>
                                </li>
                            @endif
                            @if (menu_can('menu.products_system.transfer'))
                                <li
                                    class="dash-item {{ Request::route()->getName() == 'warehouse-transfer.index' || Request::route()->getName() == 'warehouse-transfer.show' ? ' active' : '' }}">
                                    <a class="dash-link"
                                        href="{{ route('warehouse-transfer.index') }}">{{ __('Transfer') }}</a>
                                </li>
                            @endif
                            @if (menu_can('menu.products_system.warehouse_loss'))
                                <li class="dash-item {{ Request::route()->getName() == 'warehouse-loss.index' ? ' active' : '' }}">
                                    <a class="dash-link" href="{{ route('warehouse-loss.index') }}">{{ __('Warehouse Loss') }}</a>
                                </li>
                            @endif
                            @if (menu_can('menu.products_system.minimum_stock_alert'))
                                <li class="dash-item {{ Request::route()->getName() == 'reports.product.minimum-stock-alert' ? ' active' : '' }}">
                                    <a class="dash-link" href="{{ route('reports.product.minimum-stock-alert') }}">{{ __('Minimum Stock Alert') }}</a>
                                </li>
                            @endif
                            @if (menu_can('menu.products_system.reports'))
                                <li class="dash-item {{ str_starts_with((string) optional(Request::route())->getName(), 'reports.product.') ? ' active' : '' }}">
                                    <a class="dash-link" href="{{ route('reports.product.index') }}">{{ __('Reports') }}</a>
                                </li>
                            @endif
                            @if (menu_can('menu.products_system.product_service'))
                                <li class="dash-item {{ Request::segment(1) == 'productservice' ? 'active' : '' }}">
                                    <a href="{{ route('productservice.index') }}"
                                        class="dash-link">{{ __('Product & Services') }}
                                    </a>
                                </li>
                            @endif
                            @if (menu_can('menu.products_system.product_stock'))
                                <li class="dash-item {{ Request::segment(1) == 'productstock' ? 'active' : '' }}">
                                    <a href="{{ route('productstock.index') }}"
                                        class="dash-link">{{ __('Product Stock') }}
                                    </a>
                                </li>
                            @endif
                            @if (menu_can('menu.products_system.batches'))
                                <li class="dash-item {{ Request::segment(1) == 'product-batches' ? 'active' : '' }}">
                                    <a href="{{ route('product-batches.index') }}"
                                        class="dash-link">{{ __('Batch / Lot monitor') }}
                                    </a>
                                </li>
                            @endif
                            

                        </ul>
                    </li>
                @endif

                <!--------------------- End Products System ----------------------------------->


                <!--------------------- Start POs System ----------------------------------->
                @if (!empty($userPlan) && $userPlan->pos == 1)
                    @if (menu_can_node('menu.pos_system'))
                    <li class="dash-item dash-hasmenu">
                        <a href="#!" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-layers-difference"></i></span>
                            <span class="dash-mtext">{{ __('POS System') }}</span>
                            <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
                        </a>

                        <ul class="dash-submenu">

                            {{-- ================= RETAIL POS ================= --}}
                            @if (menu_can_node('menu.pos_system.retail'))
                            <li class="dash-item dash-hasmenu">
                                <a href="#!" class="dash-link">
                                    {{ __('Retail POS') }}
                                    <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
                                </a>

                                <ul class="dash-submenu">
                                    @if (menu_can('menu.pos_system.retail.pos'))
                                    <li class="dash-item {{ Request::route()->getName() == 'pos.index' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('pos.index') }}">{{ __('POS ') }}</a>
                                    </li>
                                    @endif

                                    @if (menu_can('menu.pos_system.retail.balances'))
                                    <li class="dash-item {{ Request::route()->getName() == 'pos.balances' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('pos.balances') }}">{{ __('POS Balances') }}</a>
                                        </li>
                                    @endif
                                </ul>
                            </li>
                            @endif

                            {{-- ================= RESTAURANT POS ================= --}}
                            @if (menu_can_node('menu.pos_system.restaurant'))
                            <li class="dash-item dash-hasmenu">
                                <a href="#!" class="dash-link">
                                    {{ __('Restaurant POS') }}
                                    <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
                                </a>

                                <ul class="dash-submenu">
                                    @if (menu_can('menu.pos_system.restaurant.pos'))
                                    <li class="dash-item {{ Request::route()->getName() == 'pos.restaurant.index' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('pos.restaurant.index') }}">{{ __('Restaurant POS') }}</a>
                                    </li>
                                    @endif
                                    @if (menu_can('menu.pos_system.restaurant.production'))
                                    <li class="dash-item {{ Request::route()->getName() == 'pos.production.index' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('pos.production.index') }}">{{ __('Production') }}</a>
                                    </li>
                                    @endif
                                    @if (menu_can('menu.pos_system.restaurant.areas'))
                                    <li class="dash-item {{ Request::route()->getName() == 'pos-area.index' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('pos-area.index') }}">{{ __('POS Areas') }}</a>
                                    </li>
                                    @endif

                                    @if (menu_can('menu.pos_system.restaurant.tables'))
                                    <li class="dash-item {{ Request::route()->getName() == 'pos-table.index' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('pos-table.index') }}">{{ __('POS Tables') }}</a>
                                    </li>
                                    @endif

                                    @if (menu_can('menu.pos_system.restaurant.printers'))
                                    <li class="dash-item {{ Request::route()->getName() == 'pos-printer.index' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('pos-printer.index') }}">{{ __('Kitchen/Bar Printers') }}</a>
                                    </li>
                                    @endif

                                    @if (menu_can('menu.pos_system.restaurant.day_summary'))
                                    <li class="dash-item {{ Request::route()->getName() == 'report.pos.day-summary' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('report.pos.day-summary', ['date' => date('Y-m-d')]) }}">{{ __('Day Summary') }}</a>
                                    </li>
                                    @endif

                                    @if (menu_can('menu.pos_system.restaurant.audit_log'))
                                    <li class="dash-item {{ Request::route()->getName() == 'pos.audit-log.index' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('pos.audit-log.index') }}">{{ __('POS Audit Log') }}</a>
                                    </li>
                                    @endif

                                    @if (menu_can('menu.pos_system.restaurant.low_stock'))
                                    <li class="dash-item {{ Request::route()->getName() == 'report.pos.low-stock' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('report.pos.low-stock') }}">{{ __('Minimum Stock Alert') }}</a>
                                    </li>
                                    @endif
                                    @if (menu_can('menu.pos_system.restaurant.settings'))
                                    <li class="dash-item {{ Request::route()->getName() == 'pos-restaurant-settings.index' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('pos-restaurant-settings.index') }}">{{ __('Restaurant Settings') }}</a>
                                    </li>
                                    @endif
                                    @if (menu_can('menu.pos_system.restaurant.reports'))
                                    <li class="dash-item {{ Request::route()->getName() == 'report.restaurant.index' ? 'active' : '' }}">
                                        <a class="dash-link" href="{{ route('report.restaurant.index') }}">{{ __('Restaurant Reports') }}</a>
                                    </li>
                                    @endif

                                </ul>
                            </li>
                            @endif

                            @if (menu_can('menu.pos_system.shifts'))
                            <li class="dash-item {{ Request::route()->getName() == 'pos-shift.index' ? 'active' : '' }}">
                                <a class="dash-link" href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a>
                            </li>
                            @endif

                            @if (menu_can('menu.pos_system.eod'))
                            <li class="dash-item {{ request()->is('report/pos-eod') ? 'active' : '' }}">
                                <a class="dash-link" href="{{ route('report.pos.eod') }}">{{ __('EOD Report') }}</a>
                            </li>
                            @endif

                            @if (menu_can('menu.pos_system.branch_sales_targets'))
                            <li class="dash-item">
                                <a class="dash-link" href="{{ route('branch-sales-targets.index') }}">{{ __('Branch Sales Targets') }}</a>
                            </li>
                            @endif

                            {{-- ================= BARCODE / PRINT ================= --}}
                            @if (menu_can('menu.pos_system.barcode'))
                            <li class="dash-item">
                                <a class="dash-link" href="{{ route('pos.barcode') }}">{{ __('Print Barcode') }}</a>
                            </li>
                            @endif

                            @if (menu_can('menu.pos_system.print_settings'))
                            <li class="dash-item">
                                <a class="dash-link" href="{{ route('pos.print.setting') }}">{{ __('Print Settings') }}</a>
                            </li>
                            @endif

                        </ul>
                    </li>
                    @endif
                    @endif

                <!--------------------- End POs System ----------------------------------->

                <!--------------------- Start Hotel Management ---------------------------->
                @if (isset(app('view')->getFinder()->getHints()['hotelmanagement']))
                @include('hotelmanagement::menu.hotelmanagement')
                @endif
                <!--------------------- End Hotel Management ------------------------------>

                {{-- Expense Management --}}
                @if(menu_can_node('menu.expense_management'))
                <li class="dash-item dash-hasmenu">
                        <a href="#!" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-credit-card"></i></span>
                            <span class="dash-mtext">{{ __('Expense Management') }}</span>
                            <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
                        </a>

                        <ul class="dash-submenu">
                            @if (menu_can('menu.expense_management.category'))
                            <li class="dash-item">
                                <a class="dash-link" href="{{ route('expense-category.index') }}">{{ __('Expense Category') }}</a>
                            </li>
                            @endif
                            @if (menu_can('menu.expense_management.expenses'))
                            <li class="dash-item">
                                <a class="dash-link" href="{{ route('expenses.index') }}">{{ __('Expenses') }}</a>
                            </li>
                            @endif
                        </ul>
                </li>
                @endif

                @if (\Auth::user()->type != 'super admin')
                    @if (menu_can('menu.support'))
                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'support' ? 'active' : '' }}">
                        <a href="{{ route('support.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-headphones"></i></span><span
                                class="dash-mtext">{{ __('Support System') }}</span>
                        </a>
                    </li>
                    @endif
                    @if (menu_can('menu.zoom_meeting'))
                    <li
                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'zoom-meeting' || Request::segment(1) == 'zoom-meeting-calender' ? 'active' : '' }}">
                        <a href="{{ route('zoom-meeting.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-user-check"></i></span><span
                                class="dash-mtext">{{ __('Zoom Meeting') }}</span>
                        </a>
                    </li>
                    @endif
                    @if (menu_can('menu.messenger'))
                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'chats' ? 'active' : '' }}">
                        <a href="{{ url('chats') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-message-circle"></i></span><span
                                class="dash-mtext">{{ __('Messenger') }}</span>
                        </a>
                    </li>
                    @endif
                @endif

                @if (\Auth::user()->type == 'company' && menu_can('menu.notification_template'))
                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'notification_templates' ? 'active' : '' }}">
                        <a href="{{ route('notification-templates.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-notification"></i></span><span
                                class="dash-mtext">{{ __('Notification Template') }}</span>
                        </a>
                    </li>
                @endif

                <!--------------------- Start System Setup ----------------------------------->

                @if (\Auth::user()->type != 'super admin')
                    @if (menu_can_node('menu.settings'))
                        <li
                            class="dash-item dash-hasmenu {{ Request::segment(1) == 'settings' ||
                            Request::segment(1) == 'plans' ||
                            Request::segment(1) == 'stripe' ||
                            Request::segment(1) == 'order'
                                ? ' active dash-trigger'
                                : '' }}">
                            <a href="#!" class="dash-link">
                                <span class="dash-micon"><i class="ti ti-settings"></i></span><span
                                    class="dash-mtext">{{ __('Settings') }}</span>
                                <span class="dash-arrow">
                                    <i data-feather="chevron-right"></i></span>
                            </a>
                            <ul class="dash-submenu">
                                @if (menu_can('menu.settings.system'))
                                    <li
                                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'settings' ? ' active' : '' }}">
                                        <a href="{{ route('settings') }}"
                                            class="dash-link">{{ __('System Settings') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.settings.plans'))
                                    <li
                                        class="dash-item{{ Request::route()->getName() == 'plans.index' || Request::route()->getName() == 'stripe' ? ' active' : '' }}">
                                        <a href="{{ route('plans.index') }}"
                                            class="dash-link">{{ __('Setup Subscription Plan') }}</a>
                                    </li>
                                @endif
                                @if (menu_can('menu.settings.referral'))
                                <li
                                    class="dash-item{{ Request::route()->getName() == 'referral-program.company' ? ' active' : '' }}">
                                    <a href="{{ route('referral-program.company') }}"
                                        class="dash-link">{{ __('Referral Program') }}</a>
                                </li>
                                @endif

                                @if (menu_can('menu.settings.order') && Auth::user()->type == 'company')
                                    <li class="dash-item {{ Request::segment(1) == 'order' ? 'active' : '' }}">
                                        <a href="{{ route('order.index') }}" class="dash-link">{{ __('Order') }}</a>
                                    </li>
                                @endif
                            </ul>
                        </li>
                    @endif
                @endif




                <!--------------------- End System Setup ----------------------------------->
            </ul>
        @endif
        @if (\Auth::user()->type == 'client')
            <ul class="dash-navbar">
                @if (Gate::check('manage client dashboard'))
                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'dashboard' ? ' active' : '' }}">
                        <a href="{{ route('client.dashboard.view') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-home"></i></span><span
                                class="dash-mtext">{{ __('Dashboard') }}</span>
                        </a>
                    </li>
                @endif
                @if (Gate::check('manage deal'))
                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'deals' ? ' active' : '' }}">
                        <a href="{{ route('deals.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-rocket"></i></span><span
                                class="dash-mtext">{{ __('Deals') }}</span>
                        </a>
                    </li>
                @endif
                @if (Gate::check('manage contract'))
                    <li
                        class="dash-item dash-hasmenu {{ Request::route()->getName() == 'contract.index' || Request::route()->getName() == 'contract.show' ? 'active' : '' }}">
                        <a href="{{ route('contract.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-rocket"></i></span><span
                                class="dash-mtext">{{ __('Contract') }}</span>
                        </a>
                    </li>
                @endif
                @if (Gate::check('manage project'))
                    <li class="dash-item dash-hasmenu  {{ Request::segment(1) == 'projects' ? ' active' : '' }}">
                        <a href="{{ route('projects.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-share"></i></span><span
                                class="dash-mtext">{{ __('Project') }}</span>
                        </a>
                    </li>
                @endif
                @if (Gate::check('manage project'))
                    <li
                        class="dash-item  {{ Request::route()->getName() == 'project_report.index' || Request::route()->getName() == 'project_report.show' ? 'active' : '' }}">
                        <a class="dash-link" href="{{ route('project_report.index') }}">
                            <span class="dash-micon"><i class="ti ti-chart-line"></i></span><span
                                class="dash-mtext">{{ __('Project Report') }}</span>
                        </a>
                    </li>
                @endif

                @if (Gate::check('manage project task'))
                    <li class="dash-item dash-hasmenu  {{ Request::segment(1) == 'taskboard' ? ' active' : '' }}">
                        <a href="{{ route('taskBoard.view', 'list') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-list-check"></i></span><span
                                class="dash-mtext">{{ __('Tasks') }}</span>
                        </a>
                    </li>
                @endif

                @if (Gate::check('manage bug report'))
                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'bugs-report' ? ' active' : '' }}">
                        <a href="{{ route('bugs.view', 'list') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-bug"></i></span><span
                                class="dash-mtext">{{ __('Bugs') }}</span>
                        </a>
                    </li>
                @endif

                @if (Gate::check('manage timesheet'))
                    <li
                        class="dash-item dash-hasmenu {{ Request::segment(1) == 'timesheet-list' ? ' active' : '' }}">
                        <a href="{{ route('timesheet.list') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-clock"></i></span><span
                                class="dash-mtext">{{ __('Timesheet') }}</span>
                        </a>
                    </li>
                @endif

                @if (Gate::check('manage project task'))
                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'calendar' ? ' active' : '' }}">
                        <a href="{{ route('task.calendar', ['all']) }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-calendar"></i></span><span
                                class="dash-mtext">{{ __('Task Calender') }}</span>
                        </a>
                    </li>
                @endif

                <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'support' ? 'active' : '' }}">
                    <a href="{{ route('support.index') }}" class="dash-link">
                        <span class="dash-micon"><i class="ti ti-headphones"></i></span><span
                            class="dash-mtext">{{ __('Support System') }}</span>
                    </a>
                </li>
            </ul>
        @endif
        @if (\Auth::user()->type == 'super admin')
            <ul class="dash-navbar">
                @if (Gate::check('manage super admin dashboard'))
                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'dashboard' ? ' active' : '' }}">
                        <a href="{{ route('client.dashboard.view') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-home"></i></span><span
                                class="dash-mtext">{{ __('Dashboard') }}</span>
                        </a>
                    </li>
                @endif


                @can('manage user')
                    <li
                        class="dash-item dash-hasmenu {{ Request::route()->getName() == 'users.index' || Request::route()->getName() == 'users.create' || Request::route()->getName() == 'users.edit' ? ' active' : '' }}">
                        <a href="{{ route('users.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-users"></i></span><span
                                class="dash-mtext">{{ __('Companies') }}</span>
                        </a>
                    </li>
                @endcan

                @if (Gate::check('manage plan'))
                    <li class="dash-item dash-hasmenu  {{ Request::segment(1) == 'plans' ? 'active' : '' }}">
                        <a href="{{ route('plans.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-trophy"></i></span><span
                                class="dash-mtext">{{ __('Plan') }}</span>
                        </a>
                    </li>
                @endif
                @if (\Auth::user()->type == 'super admin')
                    <li class="dash-item dash-hasmenu {{ request()->is('plan_request*') ? 'active' : '' }}">
                        <a href="{{ route('plan_request.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-arrow-up-right-circle"></i></span><span
                                class="dash-mtext">{{ __('Plan Request') }}</span>
                        </a>
                    </li>
                @endif

                <li class="dash-item dash-hasmenu  {{ Request::segment(1) == '' ? 'active' : '' }}">
                    <a href="{{ route('referral-program.index') }}" class="dash-link">
                        <span class="dash-micon"><i class="ti ti-discount-2"></i></span><span
                            class="dash-mtext">{{ __('Referral Program') }}</span>
                    </a>
                </li>


                @if (Gate::check('manage coupon'))
                    <li class="dash-item dash-hasmenu {{ Request::segment(1) == 'coupons' ? 'active' : '' }}">
                        <a href="{{ route('coupons.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-gift"></i></span><span
                                class="dash-mtext">{{ __('Coupon') }}</span>
                        </a>
                    </li>
                @endif
                @if (Gate::check('manage order'))
                    <li class="dash-item dash-hasmenu  {{ Request::segment(1) == 'orders' ? 'active' : '' }}">
                        <a href="{{ route('order.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-shopping-cart-plus"></i></span><span
                                class="dash-mtext">{{ __('Order') }}</span>
                        </a>
                    </li>
                @endif
                <li
                    class="dash-item dash-hasmenu {{ Request::segment(1) == 'email_template' || Request::route()->getName() == 'manage.email.language' ? ' active dash-trigger' : 'collapsed' }}">
                    <a href="{{ route('email_template.index') }}" class="dash-link">
                        <span class="dash-micon"><i class="ti ti-template"></i></span>
                        <span class="dash-mtext">{{ __('Email Template') }}</span>
                    </a>
                </li>
                @if (\Auth::user()->type == 'super admin' || \Auth::user()->type == 'company')
                    <li class="dash-item dash-hasmenu {{ Request::route()->getName() == 'email_logs.index' ? ' active' : '' }}">
                        <a href="{{ route('email_logs.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-mail-check"></i></span>
                            <span class="dash-mtext">{{ __('Email Logs') }}</span>
                        </a>
                    </li>
                    <li class="dash-item dash-hasmenu {{ Request::route()->getName() == 'document_void_logs.index' ? ' active' : '' }}">
                        <a href="{{ route('document_void_logs.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-file-x"></i></span>
                            <span class="dash-mtext">{{ __('Void Audit Log') }}</span>
                        </a>
                    </li>
                @endif

                @if (\Auth::user()->type == 'super admin')
                    @include('landingpage::menu.landingpage')
                @endif

                @if (Gate::check('manage system settings'))
                    <li
                        class="dash-item dash-hasmenu {{ Request::route()->getName() == 'systems.index' ? ' active' : '' }}">
                        <a href="{{ route('systems.index') }}" class="dash-link">
                            <span class="dash-micon"><i class="ti ti-settings"></i></span><span
                                class="dash-mtext">{{ __('Settings') }}</span>
                        </a>
                    </li>
                @endif

            </ul>
        @endif


    </div>
</div>
</nav>
