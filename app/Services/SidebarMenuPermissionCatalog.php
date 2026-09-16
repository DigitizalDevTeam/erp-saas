<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sidebar-mirrored menu.* permissions (visibility tree for Role UI + menu gates).
 *
 * Each node: label, permission, optional legacy[] (OR for BC), optional children[].
 */
class SidebarMenuPermissionCatalog
{
    public const GUARD = 'web';

    /**
     * @return array<int, array{label: string, permission: string, legacy?: array<int, string>, children?: array}>
     */
    public static function tree(): array
    {
        return [
            self::node('Dashboard', 'menu.dashboard', [], [
                self::node('Accounting', 'menu.dashboard.accounting', ['show account dashboard'], [
                    self::node('Overview', 'menu.dashboard.accounting.overview', ['show account dashboard']),
                    self::node('Reports', 'menu.dashboard.accounting.reports', [
                        'income report', 'expense report', 'income vs expense report', 'tax report',
                        'loss & profit report', 'bill report', 'stock report', 'invoice report',
                        'manage transaction', 'statement report', 'ledger report', 'trial balance report',
                    ], [
                        self::node('Monthly Reports', 'menu.dashboard.accounting.reports.monthly', ['tax report', 'trial balance report', 'manage report'], [
                            self::node('VAT (Modelo A IVA)', 'menu.dashboard.accounting.reports.monthly.vat_modelo_a', ['tax report']),
                            self::node('Trial Balance (Balancete)', 'menu.dashboard.accounting.reports.monthly.trial_balance', ['trial balance report']),
                            self::node('IRPS (Modelo 19)', 'menu.dashboard.accounting.reports.monthly.irps_modelo_19', ['manage report']),
                            self::node('IRPS / Payroll (Model 22)', 'menu.dashboard.accounting.reports.monthly.irps_modelo_22', ['manage report']),
                            self::node('IRPC Payment Guide (Annual)', 'menu.dashboard.accounting.reports.monthly.irpc_payment_guide', ['tax report']),
                        ]),
                        self::node('Management Reports', 'menu.dashboard.accounting.reports.management', [
                            'statement report', 'invoice report', 'bill report', 'stock report',
                            'loss & profit report', 'manage transaction', 'income report', 'expense report',
                            'income vs expense report', 'tax report', 'manage report',
                        ], [
                            self::node('Account Statement', 'menu.dashboard.accounting.reports.management.account_statement', ['statement report']),
                            self::node('Invoice Summary', 'menu.dashboard.accounting.reports.management.invoice_summary', ['invoice report']),
                            self::node('Sales Report', 'menu.dashboard.accounting.reports.management.sales', []),
                            self::node('Receivables', 'menu.dashboard.accounting.reports.management.receivables', []),
                            self::node('Payables', 'menu.dashboard.accounting.reports.management.payables', []),
                            self::node('Bill Summary', 'menu.dashboard.accounting.reports.management.bill_summary', ['bill report']),
                            self::node('Product Stock', 'menu.dashboard.accounting.reports.management.product_stock', ['stock report']),
                            self::node('Minimum Stock Alert', 'menu.dashboard.accounting.reports.management.minimum_stock_alert', ['stock report']),
                            self::node('Cash Flow', 'menu.dashboard.accounting.reports.management.cash_flow', ['loss & profit report']),
                            self::node('Transaction', 'menu.dashboard.accounting.reports.management.transaction', ['manage transaction']),
                            self::node('Income Summary', 'menu.dashboard.accounting.reports.management.income_summary', ['income report']),
                            self::node('Expense Summary', 'menu.dashboard.accounting.reports.management.expense_summary', ['expense report']),
                            self::node('Income VS Expense', 'menu.dashboard.accounting.reports.management.income_vs_expense', ['income vs expense report']),
                            self::node('Tax Summary', 'menu.dashboard.accounting.reports.management.tax_summary', ['tax report']),
                            self::node('Credit Note Report', 'menu.dashboard.accounting.reports.management.credit_note', ['manage report']),
                            self::node('Debit Note Report', 'menu.dashboard.accounting.reports.management.debit_note', ['manage report']),
                        ]),
                        self::node('Analytical Reports', 'menu.dashboard.accounting.reports.analytical', ['manage report'], [
                            self::node('PowerBlox - Management Pack', 'menu.dashboard.accounting.reports.analytical.powerblox', ['manage report']),
                        ]),
                        self::node('Ledger Summary', 'menu.dashboard.accounting.reports.ledger_summary', ['ledger report']),
                        self::node('Balance Sheet', 'menu.dashboard.accounting.reports.balance_sheet', ['bill report']),
                        self::node('Profit & Loss', 'menu.dashboard.accounting.reports.profit_loss', ['income vs expense report']),
                        self::node('Income statement (comparative)', 'menu.dashboard.accounting.reports.income_statement_comparative', ['income vs expense report']),
                        self::node('Trial Balance', 'menu.dashboard.accounting.reports.trial_balance', ['trial balance report']),
                    ]),
                ]),
                self::node('Product system', 'menu.dashboard.product_system', ['stock report'], [
                    self::node('Overview', 'menu.dashboard.product_system.overview', ['stock report']),
                    self::node('Store check', 'menu.dashboard.product_system.store_check', ['stock report']),
                    self::node('Store Check History', 'menu.dashboard.product_system.store_check_history', ['stock report']),
                    self::node('Warehouse stock', 'menu.dashboard.product_system.warehouse_stock', ['stock report']),
                    self::node('Total stock value', 'menu.dashboard.product_system.total_stock_value', ['stock report']),
                    self::node('High-value stock movements', 'menu.dashboard.product_system.high_rotation', ['stock report']),
                    self::node('Stock Movement report', 'menu.dashboard.product_system.stock_movement', ['stock report']),
                    self::node('Out-of-stock products', 'menu.dashboard.product_system.out_of_stock', ['stock report']),
                    self::node('Minimum Stock Alert', 'menu.dashboard.product_system.minimum_stock_alert', ['stock report']),
                    self::node('Products near expiry', 'menu.dashboard.product_system.near_expiry', ['stock report']),
                    self::node('Expired products', 'menu.dashboard.product_system.expired_products', ['stock report']),
                    self::node('Transactions', 'menu.dashboard.product_system.transactions', ['stock report']),
                    self::node('Last 5 supplies', 'menu.dashboard.product_system.last_supplies', ['stock report']),
                    self::node('Row & column check', 'menu.dashboard.product_system.location_check', ['stock report']),
                ]),
                self::node('HRM', 'menu.dashboard.hrm', ['show hrm dashboard'], [
                    self::node('Overview', 'menu.dashboard.hrm.overview', ['show hrm dashboard']),
                    self::node('Reports', 'menu.dashboard.hrm.reports', ['manage report'], [
                        self::node('Payroll', 'menu.dashboard.hrm.reports.payroll', ['manage report']),
                        self::node('Leave', 'menu.dashboard.hrm.reports.leave', ['manage report']),
                        self::node('Monthly Attendance', 'menu.dashboard.hrm.reports.monthly_attendance', ['manage report']),
                        self::node('Tax report', 'menu.dashboard.hrm.reports.tax', ['manage report']),
                    ]),
                ]),
                self::node('CRM', 'menu.dashboard.crm', ['show crm dashboard'], [
                    self::node('Overview', 'menu.dashboard.crm.overview', ['show crm dashboard']),
                    self::node('Reports', 'menu.dashboard.crm.reports', ['show crm dashboard'], [
                        self::node('Lead', 'menu.dashboard.crm.reports.lead', ['show crm dashboard']),
                        self::node('Deal', 'menu.dashboard.crm.reports.deal', ['show crm dashboard']),
                    ]),
                ]),
                self::node('Sales Users & Clients', 'menu.dashboard.sales', ['show sales dashboard']),
                self::node('Project', 'menu.dashboard.project', ['show project dashboard']),
                self::node('POS', 'menu.dashboard.pos', ['show pos dashboard'], [
                    self::node('Overview', 'menu.dashboard.pos.overview', ['show pos dashboard']),
                    self::node('Reports', 'menu.dashboard.pos.reports', ['manage pos'], [
                        self::node('Warehouse Report', 'menu.dashboard.pos.reports.warehouse', ['manage pos']),
                        self::node('Purchase Daily/Monthly Report', 'menu.dashboard.pos.reports.purchase_daily', ['manage pos']),
                        self::node('POS Daily/Monthly Report', 'menu.dashboard.pos.reports.pos_daily', ['manage pos']),
                        self::node('Pos VS Purchase Report', 'menu.dashboard.pos.reports.pos_vs_purchase', ['manage pos']),
                        self::node('POS by Branch', 'menu.dashboard.pos.reports.pos_by_branch', ['manage pos']),
                    ]),
                ]),
            ]),

            self::node('HRM System', 'menu.hrm_system', [
                'manage employee', 'manage set salary', 'manage pay slip', 'manage leave', 'manage attendance',
            ], [
                self::node('Employee Setup', 'menu.hrm_system.employee', ['manage employee']),
                self::node('Payroll Setup', 'menu.hrm_system.payroll', ['manage set salary', 'manage pay slip'], [
                    self::node('Set salary', 'menu.hrm_system.payroll.set_salary', ['manage set salary']),
                    self::node('Payslip', 'menu.hrm_system.payroll.payslip', ['manage pay slip']),
                ]),
                self::node('Leave Management Setup', 'menu.hrm_system.leave', ['manage leave', 'manage attendance'], [
                    self::node('Manage Leave', 'menu.hrm_system.leave.manage', ['manage leave']),
                    self::node('Attendance', 'menu.hrm_system.leave.attendance', ['manage attendance'], [
                        self::node('Mark Attendance', 'menu.hrm_system.leave.attendance.mark', ['manage attendance']),
                        self::node('Bulk Attendance', 'menu.hrm_system.leave.attendance.bulk', ['create attendance']),
                    ]),
                ]),
                self::node('Performance Setup', 'menu.hrm_system.performance', ['manage indicator', 'manage appraisal', 'manage goal tracking'], [
                    self::node('Indicator', 'menu.hrm_system.performance.indicator', ['manage indicator']),
                    self::node('Appraisal', 'menu.hrm_system.performance.appraisal', ['manage appraisal']),
                    self::node('Goal Tracking', 'menu.hrm_system.performance.goal_tracking', ['manage goal tracking']),
                ]),
                self::node('Training Setup', 'menu.hrm_system.training', ['manage training', 'manage trainer'], [
                    self::node('Training List', 'menu.hrm_system.training.list', ['manage training']),
                    self::node('Trainer', 'menu.hrm_system.training.trainer', ['manage trainer']),
                ]),
                self::node('Recruitment Setup', 'menu.hrm_system.recruitment', [
                    'manage job', 'create job', 'manage job application', 'manage job onBoard',
                    'manage custom question', 'show interview schedule', 'show career',
                ], [
                    self::node('Jobs', 'menu.hrm_system.recruitment.jobs', ['manage job']),
                    self::node('Job Create', 'menu.hrm_system.recruitment.job_create', ['create job']),
                    self::node('Job Application', 'menu.hrm_system.recruitment.job_application', ['manage job application']),
                    self::node('Job Candidate', 'menu.hrm_system.recruitment.job_candidate', ['manage job onBoard']),
                    self::node('Job On-boarding', 'menu.hrm_system.recruitment.job_onboard', ['manage job onBoard']),
                    self::node('Custom Question', 'menu.hrm_system.recruitment.custom_question', ['manage custom question']),
                    self::node('Interview Schedule', 'menu.hrm_system.recruitment.interview_schedule', ['show interview schedule']),
                    self::node('Career', 'menu.hrm_system.recruitment.career', ['show career']),
                ]),
                self::node('HR Admin Setup', 'menu.hrm_system.hr_admin', [
                    'manage award', 'manage transfer', 'manage resignation', 'manage travel', 'manage promotion',
                    'manage complaint', 'manage warning', 'manage termination', 'manage announcement', 'manage holiday',
                ], [
                    self::node('Award', 'menu.hrm_system.hr_admin.award', ['manage award']),
                    self::node('Transfer', 'menu.hrm_system.hr_admin.transfer', ['manage transfer']),
                    self::node('Resignation', 'menu.hrm_system.hr_admin.resignation', ['manage resignation']),
                    self::node('Trip', 'menu.hrm_system.hr_admin.travel', ['manage travel']),
                    self::node('Promotion', 'menu.hrm_system.hr_admin.promotion', ['manage promotion']),
                    self::node('Complaints', 'menu.hrm_system.hr_admin.complaint', ['manage complaint']),
                    self::node('Warning', 'menu.hrm_system.hr_admin.warning', ['manage warning']),
                    self::node('Termination', 'menu.hrm_system.hr_admin.termination', ['manage termination']),
                    self::node('Announcement', 'menu.hrm_system.hr_admin.announcement', ['manage announcement']),
                    self::node('Holidays', 'menu.hrm_system.hr_admin.holiday', ['manage holiday']),
                ]),
                self::node('Event Setup', 'menu.hrm_system.event', ['manage event']),
                self::node('Meeting', 'menu.hrm_system.meeting', ['manage meeting']),
                self::node('Employees Asset Setup', 'menu.hrm_system.assets', ['manage assets']),
                self::node('Document Setup', 'menu.hrm_system.document', ['manage document']),
                self::node('Company policy', 'menu.hrm_system.company_policy', ['manage company policy']),
                self::node('Branch', 'menu.hrm_system.branch', ['manage branch']),
            ]),

            self::node('Accounting System', 'menu.accounting_system', [
                'manage bank account', 'manage customer', 'manage invoice', 'manage bill', 'manage chart of account',
            ], [
                self::node('Banking', 'menu.accounting_system.banking', ['manage bank account', 'manage bank transfer', 'manage transaction'], [
                    self::node('Account', 'menu.accounting_system.banking.account', ['manage bank account']),
                    self::node('Transfer', 'menu.accounting_system.banking.transfer', ['manage bank transfer']),
                    self::node('Bank reconciliation', 'menu.accounting_system.banking.reconciliation', ['manage transaction']),
                ]),
                self::node('Sales', 'menu.accounting_system.sales', [
                    'manage customer', 'manage proposal', 'manage invoice', 'manage quotation', 'manage revenue', 'manage credit note',
                ], [
                    self::node('Customer', 'menu.accounting_system.sales.customer', ['manage customer']),
                    self::node('Estimate', 'menu.accounting_system.sales.estimate', ['manage proposal']),
                    self::node('Invoice', 'menu.accounting_system.sales.invoice', ['manage invoice']),
                    self::node('Quotation', 'menu.accounting_system.sales.quotation', ['manage quotation']),
                    self::node('Sales Proforma Invoice', 'menu.accounting_system.sales.proforma', ['manage quotation']),
                    self::node('Revenue', 'menu.accounting_system.sales.revenue', ['manage revenue']),
                    self::node('Credit Note', 'menu.accounting_system.sales.credit_note', ['manage credit note']),
                    self::node('Debit Note', 'menu.accounting_system.sales.debit_note', ['manage credit note']),
                ]),
                self::node('Purchases', 'menu.accounting_system.purchases', [
                    'manage vender', 'manage bill', 'manage payment', 'manage debit note', 'manage purchase',
                ], [
                    self::node('Supplier', 'menu.accounting_system.purchases.vender', ['manage vender']),
                    self::node('Vendor Quote', 'menu.accounting_system.purchases.vendor_quote', ['manage purchase']),
                    self::node('Purchase Proforma Invoice', 'menu.accounting_system.purchases.purchase_proforma', ['manage purchase']),
                    self::node('Purchase Order', 'menu.accounting_system.purchases.purchase', ['manage purchase']),
                    self::node('Purchase Invoice', 'menu.accounting_system.purchases.bill', ['manage bill']),
                    self::node('Expense', 'menu.accounting_system.purchases.expense', ['manage bill']),
                    self::node('Payment', 'menu.accounting_system.purchases.payment', ['manage payment']),
                    self::node('Debit Note', 'menu.accounting_system.purchases.debit_note', ['manage debit note']),
                    self::node('Credit Note', 'menu.accounting_system.purchases.credit_note', ['manage debit note']),
                ]),
                self::node('Double Entry', 'menu.accounting_system.double_entry', ['manage chart of account', 'manage journal entry'], [
                    self::node('Chart of Accounts', 'menu.accounting_system.double_entry.chart_of_account', ['manage chart of account']),
                    self::node('Journal Account', 'menu.accounting_system.double_entry.journal_entry', ['manage journal entry']),
                    self::node('Fiscal year closing', 'menu.accounting_system.double_entry.fiscal_year_closing', ['manage journal entry']),
                ]),
                self::node('Budget Planner', 'menu.accounting_system.budget', ['manage budget plan']),
                self::node('Financial Goal', 'menu.accounting_system.goal', ['manage goal']),
                self::node('Accounting Setup', 'menu.accounting_system.setup', ['manage constant tax', 'manage constant category', 'manage constant unit']),
                self::node('Print Settings', 'menu.accounting_system.print_settings', ['manage print settings']),
                self::node('Layout Management', 'menu.accounting_system.layout_management', ['manage print settings', 'manage document layouts']),
            ]),

            self::node('CRM System', 'menu.crm_system', ['manage lead', 'manage deal', 'manage form builder', 'manage contract'], [
                self::node('Leads', 'menu.crm_system.leads', ['manage lead']),
                self::node('Deals', 'menu.crm_system.deals', ['manage deal']),
                self::node('Form Builder', 'menu.crm_system.form_builder', ['manage form builder']),
                self::node('Contract', 'menu.crm_system.contract', ['manage contract']),
                self::node('CRM System Setup', 'menu.crm_system.setup', [
                    'manage lead stage', 'manage pipeline', 'manage source', 'manage label', 'manage contract type', 'manage stage',
                ]),
            ]),

            self::node('Project System', 'menu.project_system', [
                'manage project', 'manage project task', 'manage timesheet', 'manage bug report',
            ], [
                self::node('Projects', 'menu.project_system.projects', ['manage project']),
                self::node('Tasks', 'menu.project_system.tasks', ['manage project task']),
                self::node('Timesheet', 'menu.project_system.timesheet', ['manage timesheet']),
                self::node('Bug', 'menu.project_system.bug', ['manage bug report']),
                self::node('Task Calendar', 'menu.project_system.task_calendar', ['manage project task']),
                self::node('Tracker', 'menu.project_system.tracker', []),
                self::node('Project Report', 'menu.project_system.project_report', []),
                self::node('Project System Setup', 'menu.project_system.setup', ['manage project task stage', 'manage bug status'], [
                    self::node('Project Task Stages', 'menu.project_system.setup.task_stages', ['manage project task stage']),
                    self::node('Bug Status', 'menu.project_system.setup.bug_status', ['manage bug status']),
                ]),
            ]),

            self::node('User Management', 'menu.user_management', ['manage user', 'manage role', 'manage client'], [
                self::node('User', 'menu.user_management.user', ['manage user']),
                self::node('Role', 'menu.user_management.role', ['manage role']),
                self::node('Client', 'menu.user_management.client', ['manage client']),
            ]),

            self::node('Products System', 'menu.products_system', ['manage warehouse', 'manage product & service', 'stock report'], [
                self::node('Warehouse', 'menu.products_system.warehouse', ['manage warehouse']),
                self::node('Transfer', 'menu.products_system.transfer', ['manage warehouse']),
                self::node('Warehouse Loss', 'menu.products_system.warehouse_loss', ['manage warehouse']),
                self::node('Minimum Stock Alert', 'menu.products_system.minimum_stock_alert', ['stock report']),
                self::node('Reports', 'menu.products_system.reports', ['stock report']),
                self::node('Product & Services', 'menu.products_system.product_service', ['manage product & service']),
                self::node('Product Stock', 'menu.products_system.product_stock', ['manage product & service']),
                self::node('Batch / Lot monitor', 'menu.products_system.batches', ['manage product & service']),
            ]),

            self::node('POS System', 'menu.pos_system', [
                'manage pos', 'pos restaurant full', 'pos restaurant waiter', 'pos restaurant manager', 'manage pos production',
            ], [
                self::node('Retail POS', 'menu.pos_system.retail', ['manage pos', 'pos restaurant full', 'pos restaurant waiter', 'pos restaurant manager'], [
                    self::node('POS', 'menu.pos_system.retail.pos', ['manage pos', 'pos restaurant full', 'pos restaurant waiter', 'pos restaurant manager']),
                    self::node('POS Balances', 'menu.pos_system.retail.balances', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                ]),
                self::node('Restaurant POS', 'menu.pos_system.restaurant', [
                    'manage pos', 'pos restaurant full', 'pos restaurant waiter', 'pos restaurant manager', 'manage pos production',
                ], [
                    self::node('Restaurant POS', 'menu.pos_system.restaurant.pos', ['manage pos', 'pos restaurant full', 'pos restaurant waiter', 'pos restaurant manager']),
                    self::node('Production', 'menu.pos_system.restaurant.production', ['manage pos', 'pos restaurant manager', 'manage pos production']),
                    self::node('POS Areas', 'menu.pos_system.restaurant.areas', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                    self::node('POS Tables', 'menu.pos_system.restaurant.tables', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                    self::node('Kitchen/Bar Printers', 'menu.pos_system.restaurant.printers', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                    self::node('Day Summary', 'menu.pos_system.restaurant.day_summary', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                    self::node('POS Audit Log', 'menu.pos_system.restaurant.audit_log', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                    self::node('Minimum Stock Alert', 'menu.pos_system.restaurant.low_stock', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                    self::node('Restaurant Settings', 'menu.pos_system.restaurant.settings', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                    self::node('Restaurant Reports', 'menu.pos_system.restaurant.reports', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                ]),
                self::node('POS Shifts', 'menu.pos_system.shifts', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                self::node('EOD Report', 'menu.pos_system.eod', ['manage pos', 'pos restaurant full', 'pos restaurant manager']),
                self::node('Branch Sales Targets', 'menu.pos_system.branch_sales_targets', ['manage pos']),
                self::node('Print Barcode', 'menu.pos_system.barcode', ['create barcode']),
                self::node('Print Settings', 'menu.pos_system.print_settings', ['manage pos']),
            ]),

            // Hotel: reuse existing hotel.menu.* permission names (no new menu.hotel.* duplicates)
            self::node('Hotel Management', 'hotel.menu.module', ['manage hotel'], [
                self::node('Dashboard', 'hotel.menu.dashboard', ['show hotel dashboard', 'manage hotel']),
                self::node('Front Office', 'menu.hotel.front_office', ['manage hotel reservation', 'manage hotel frontdesk', 'show hotel reservation', 'show hotel frontdesk'], [
                    self::node('Reservations', 'hotel.menu.reservations', ['manage hotel reservation', 'show hotel reservation', 'manage hotel']),
                    self::node('Reception', 'hotel.menu.frontdesk', ['manage hotel frontdesk', 'show hotel frontdesk', 'manage hotel']),
                    self::node('Guests', 'hotel.menu.guest_map', ['manage hotel frontdesk', 'show hotel frontdesk', 'manage hotel']),
                    self::node('Guest Map', 'menu.hotel.guest_map_page', ['manage hotel frontdesk', 'show hotel frontdesk', 'manage hotel']),
                    self::node('Booking Calendar', 'hotel.menu.calendar', ['manage hotel reservation', 'show hotel reservation', 'manage hotel']),
                    self::node('Group Bookings', 'hotel.menu.groups', ['manage hotel reservation', 'show hotel reservation', 'manage hotel']),
                ]),
                self::node('Operations', 'menu.hotel.operations', ['manage hotel frontdesk', 'manage hotel room', 'manage hotel'], [
                    self::node('Housekeeping', 'hotel.menu.housekeeping', ['manage hotel frontdesk', 'show hotel frontdesk', 'manage hotel']),
                    self::node('Daily Minibar', 'menu.hotel.minibar', ['manage hotel frontdesk', 'show hotel frontdesk', 'manage hotel']),
                    self::node('Room Management', 'hotel.menu.rooms_setup', ['manage hotel room', 'show hotel room', 'manage hotel'], [
                        self::node('Floors', 'menu.hotel.floors', ['manage hotel room', 'show hotel room', 'manage hotel']),
                        self::node('Rooms', 'menu.hotel.rooms', ['manage hotel room', 'show hotel room', 'manage hotel']),
                        self::node('Room Map', 'menu.hotel.room_map', ['manage hotel room', 'show hotel room', 'manage hotel']),
                        self::node('Room Types', 'menu.hotel.room_types', ['manage hotel room', 'show hotel room', 'manage hotel']),
                    ]),
                ]),
                self::node('Sales / Booking', 'menu.hotel.sales', ['manage hotel room', 'manage hotel'], [
                    self::node('Booking Site', 'hotel.menu.booking_site', ['manage hotel', 'manage hotel room']),
                    self::node('Price per Period', 'menu.hotel.rate_plans', ['manage hotel room', 'show hotel room', 'manage hotel']),
                    self::node('Add-ons & Extras', 'menu.hotel.addons', ['manage hotel room', 'show hotel room', 'manage hotel']),
                ]),
                self::node('Finance', 'menu.hotel.finance', ['manage hotel frontdesk', 'manage hotel'], [
                    self::node('Cashier Shifts', 'menu.hotel.cashier_shifts', ['manage hotel frontdesk', 'show hotel frontdesk', 'manage hotel']),
                    self::node('Hotel Expenses', 'menu.hotel.expenses', ['manage hotel frontdesk', 'show hotel frontdesk', 'manage hotel']),
                    self::node('Hotel Accounting (COA)', 'hotel.menu.accounting', ['manage hotel']),
                ]),
                self::node('Reports', 'menu.hotel.reports_folder', ['manage hotel'], [
                    self::node('Hotel Reports', 'hotel.menu.reports', ['manage hotel', 'show hotel dashboard']),
                    self::node('Night Audit', 'hotel.menu.night_audit', ['manage hotel frontdesk', 'manage hotel']),
                ]),
                self::node('Settings', 'menu.hotel.settings', ['manage hotel'], [
                    self::node('OTA / Integrations', 'hotel.menu.ota', ['manage hotel']),
                ]),
            ]),

            self::node('Expense Management', 'menu.expense_management', ['manage expense', 'manage expense category'], [
                self::node('Expense Category', 'menu.expense_management.category', ['manage expense category']),
                self::node('Expenses', 'menu.expense_management.expenses', ['manage expense']),
            ]),

            self::node('Support System', 'menu.support', []),
            self::node('Zoom Meeting', 'menu.zoom_meeting', ['manage zoom meeting']),
            self::node('Messenger', 'menu.messenger', []),
            self::node('Notification Template', 'menu.notification_template', []),

            self::node('Settings', 'menu.settings', ['manage company settings', 'manage company plan', 'manage order'], [
                self::node('System Settings', 'menu.settings.system', ['manage company settings']),
                self::node('Setup Subscription Plan', 'menu.settings.plans', ['manage company plan']),
                self::node('Referral Program', 'menu.settings.referral', []),
                self::node('Order', 'menu.settings.order', ['manage order']),
            ]),
        ];
    }

    /**
     * @param  array<int, string>  $legacy
     * @param  array<int, array>  $children
     * @return array{label: string, permission: string, legacy: array<int, string>, children: array}
     */
    protected static function node(string $label, string $permission, array $legacy = [], array $children = []): array
    {
        return [
            'label' => $label,
            'permission' => $permission,
            'legacy' => $legacy,
            'children' => $children,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allPermissionNames(): array
    {
        $names = [];
        self::walk(self::tree(), function (array $node) use (&$names) {
            $names[] = $node['permission'];
        });

        return array_values(array_unique($names));
    }

    /**
     * Flat map: menu permission => legacy permission names.
     *
     * @return array<string, array<int, string>>
     */
    public static function legacyMap(): array
    {
        $map = [];
        self::walk(self::tree(), function (array $node) use (&$map) {
            $map[$node['permission']] = $node['legacy'] ?? [];
        });

        return $map;
    }

    public static function ensurePermissionsExist(): void
    {
        foreach (self::allPermissionNames() as $name) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => self::GUARD],
                ['name' => $name, 'guard_name' => self::GUARD]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Whether the user may see this menu permission (menu.* OR any legacy).
     */
    public static function userCanPermission(string $permission, Authenticatable|User|null $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if ($user->can($permission)) {
            return true;
        }

        foreach (self::legacyMap()[$permission] ?? [] as $legacy) {
            if ($user->can($legacy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user may see a tree node (own permission/legacy OR any descendant).
     *
     * @param  array{permission: string, legacy?: array, children?: array}  $node
     */
    public static function userCanSeeNode(array $node, Authenticatable|User|null $user = null): bool
    {
        if (self::userCanPermission($node['permission'], $user)) {
            return true;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (self::userCanSeeNode($child, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find node by permission name.
     *
     * @return array|null
     */
    public static function findNode(string $permission): ?array
    {
        $found = null;
        self::walk(self::tree(), function (array $node) use ($permission, &$found) {
            if ($node['permission'] === $permission) {
                $found = $node;
            }
        });

        return $found;
    }

    /**
     * @param  array<int, array>  $nodes
     * @param  callable(array): void  $callback
     */
    protected static function walk(array $nodes, callable $callback): void
    {
        foreach ($nodes as $node) {
            $callback($node);
            if (! empty($node['children'])) {
                self::walk($node['children'], $callback);
            }
        }
    }
}
