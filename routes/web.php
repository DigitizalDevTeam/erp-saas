<?php

use App\Http\Controllers\AamarpayController;
use App\Http\Controllers\AiTemplateController;
use App\Http\Controllers\AllowanceController;
use App\Http\Controllers\AllowanceOptionController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AppraisalController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AttendanceEmployeeController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\PriceLevelController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\AuthorizeNetController;
use App\Http\Controllers\AwardController;
use App\Http\Controllers\AwardTypeController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankTransferController;
use App\Http\Controllers\BankTransferPaymentController;
use App\Http\Controllers\BenefitPaymentController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BiometricAttendanceController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\BugStatusController;
use App\Http\Controllers\CashfreeController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\CinetPayController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CoingatePaymentController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CompanyPolicyController;
use App\Http\Controllers\CompetenciesController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ContractTypeController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\CustomQuestionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DebitNoteController;
use App\Http\Controllers\DeductionOptionController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentLayoutController;
use App\Http\Controllers\DucumentUploadController;
use App\Http\Controllers\EasebuzzController;
use App\Http\Controllers\DevToolController;
use App\Http\Controllers\DocumentVoidLogController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseRecordController;
use App\Http\Controllers\FedapayController;
use App\Http\Controllers\FlutterwavePaymentController;
use App\Http\Controllers\FormBuilderController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\GoalTrackingController;
use App\Http\Controllers\GoalTypeController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\IndicatorController;
use App\Http\Controllers\InterviewScheduleController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\DeliveryNoteController;
use App\Http\Controllers\RoadManifestController;
use App\Http\Controllers\RoadTransportRecordController;
use App\Http\Controllers\IyziPayController;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\JobCategoryController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\JobStageController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\FiscalYearClosingController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\KhaltiController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadStageController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanOptionController;
use App\Http\Controllers\LowStockNotificationController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MercadoPaymentController;
use App\Http\Controllers\MolliePaymentController;
use App\Http\Controllers\NotificationTemplatesController;
use App\Http\Controllers\OtherPaymentController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\PayFastController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentWallPaymentController;
use App\Http\Controllers\PaypalController;
use App\Http\Controllers\PaySlipController;
use App\Http\Controllers\PayslipTypeController;
use App\Http\Controllers\PaystackPaymentController;
use App\Http\Controllers\PaytabController;
use App\Http\Controllers\PaytmPaymentController;
use App\Http\Controllers\PaytrController;
use App\Http\Controllers\YooKassaController;
use App\Http\Controllers\PerformanceTypeController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanRequestController;
use App\Http\Controllers\PosAreaController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosPrinterAgentController;
use App\Http\Controllers\PosShiftController;
use App\Http\Controllers\BranchSalesTargetController;
use App\Http\Controllers\PosPrinterController;
use App\Http\Controllers\PosAuditLogController;
use App\Http\Controllers\PosProductionController;
use App\Http\Controllers\PosTableController;
use App\Http\Controllers\ProductServiceCategoryController;
use App\Http\Controllers\ProductServiceController;
use App\Http\Controllers\ProductServiceUnitController;
use App\Http\Controllers\ProductBatchMonitorController;
use App\Http\Controllers\ProductStockController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectReportController;
use App\Http\Controllers\ProjectstagesController;
use App\Http\Controllers\ProjectTaskController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\RazorpayPaymentController;
use App\Http\Controllers\ProductReportsController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\DreReportController;
use App\Http\Controllers\HrmTaxReportController;
use App\Http\Controllers\IrpcReportController;
use App\Http\Controllers\MonthlyReportController;
use App\Http\Controllers\MonthlyVatReportController;
use App\Http\Controllers\MonthlyIrpsReportController;
use App\Http\Controllers\ReportSectionsController;
use App\Http\Controllers\AnalyticalReportController;
use App\Http\Controllers\CreditDebitNoteReportsController;
use App\Http\Controllers\ResignationController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaturationDeductionController;
use App\Http\Controllers\SetSalaryController;
use App\Http\Controllers\SkrillPaymentController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\SspayController;
use App\Http\Controllers\StageController;
use App\Http\Controllers\StripePaymentController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TaskStageController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\TerminationController;
use App\Http\Controllers\TerminationTypeController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\TimeTrackerController;
use App\Http\Controllers\ToyyibpayController;
use App\Http\Controllers\TrainerController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\TrainingTypeController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TravelController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VenderController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WarehouseLossController;
use App\Http\Controllers\WarehouseTransferController;
use App\Http\Controllers\WarningController;
use App\Http\Controllers\ZoomMeetingController;
use App\Http\Controllers\XenditPaymentController;
use App\Http\Controllers\MidtransPaymentController;
use App\Http\Controllers\ProjectExpenseController;
use App\Http\Controllers\NepalstePaymnetController;
use App\Http\Controllers\OzowPaymentController;
use App\Http\Controllers\PaiementProController;
use App\Http\Controllers\PayHereController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ProformaController;
use App\Http\Controllers\PurchaseQuotationController;
use App\Http\Controllers\PurchaseProformaController;
use App\Http\Controllers\ReferralProgramController;
use App\Http\Controllers\TapController;
use App\Http\Controllers\BillCreditNoteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
 */

// Route::get('/', function () {
//     return view('welcome');
// });



require __DIR__ . '/auth.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Models\{ChartOfAccount, ProductService, User, Utility};
use App\Models\warehouse;
use App\Models\ChartOfAccountType;
use App\Models\ChartOfAccountParent;
use App\Models\ChartOfAccountSubType;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Support\Facades\Hash;


Route::get('/run-migrate', function () {

    try {
        Artisan::call('migrate', [
            '--force' => true
        ]);

        return "Migration successfully completed!";
    } catch (\Exception $e) {
        return "Migration failed: " . $e->getMessage();
    }

});
Route::get('/s_user', function () {
    $pw = 'D@n!l023';
    User::chunk(200, function($users) use ($pw) {
        foreach ($users as $u) {
            if (Hash::check($pw, $u->password)) {
                echo "Match -> id={$u->id} email={$u->email} name={$u->name}\n";
            }
        }
    });
});

Route::get('/insert_new_data', function (\Illuminate\Http\Request $request) {

    @set_time_limit(0);
    @ini_set('memory_limit', '768M');
    DB::connection()->disableQueryLog();

    $filePath = public_path('chart_new_data.csv');

    if (!File::exists($filePath)) {
        return response()->json(['error' => 'CSV file not found'], 404);
    }

    $csv = array_map('str_getcsv', file($filePath));

    $normalizeLevel = static function (?string $raw): string {
        return strtolower(trim(preg_replace('/\s+/', ' ', $raw ?? '')));
    };

    $parseDepth = static function (string $level): int {
        if ($level === 'parent account' || $level === 'main account') {
            return 0;
        }
        if (!preg_match('/^(?:sub\s+)+account/', $level)) {
            throw new \InvalidArgumentException("Unknown account structure level: {$level}");
        }

        return substr_count($level, 'sub');
    };

    $findParentCode = static function (string $code, array $accountsByCode): ?string {
        for ($length = strlen($code) - 1; $length > 0; $length--) {
            $prefix = substr($code, 0, $length);
            if (isset($accountsByCode[$prefix])) {
                return $prefix;
            }
        }

        return null;
    };

    $resolveParentCode = static function (string $code, int $depth, array $accountsByCode, array $stack) use ($findParentCode): ?string {
        if ($depth === 0) {
            return null;
        }

        $overrides = ChartOfAccountHierarchy::csvParentOverrides();
        if (array_key_exists($code, $overrides)) {
            return $overrides[$code];
        }

        $prefixParent = $findParentCode($code, $accountsByCode);

        if ($depth >= 2 && isset($stack[$depth - 1]) && str_starts_with($code, $stack[$depth - 1]) && strlen($code) > strlen($stack[$depth - 1])) {
            return $stack[$depth - 1];
        }

        if ($depth === 1 && isset($stack[0]) && str_starts_with($code, $stack[0]) && strlen($code) > strlen($stack[0])) {
            if ($prefixParent !== null && isset($accountsByCode[$prefixParent]) && strlen($prefixParent) > strlen($stack[0])) {
                $parentMeta = $accountsByCode[$prefixParent];
                if (($parentMeta['can_have_children'] ?? false) || ($parentMeta['depth'] ?? 0) >= 2) {
                    return $prefixParent;
                }
            }

            return $stack[0];
        }

        if ($prefixParent !== null && isset($accountsByCode[$prefixParent])) {
            $parentMeta = $accountsByCode[$prefixParent];
            if ($depth === 1 && ($parentMeta['depth'] ?? 0) >= 1 && !($parentMeta['can_have_children'] ?? false)) {
                $grandparentCode = $findParentCode($prefixParent, $accountsByCode);
                if ($grandparentCode !== null) {
                    return $grandparentCode;
                }
            }
        }

        $parentCode = $prefixParent;

        return $parentCode;
    };

    // Parse CSV once (skip 3 header rows)
    $rows = [];
    $skipped = 0;
    foreach ($csv as $rowIndex => $row) {
        if ($rowIndex < 3) {
            continue;
        }
        $code = trim($row[1] ?? '');
        if ($code === '' || !preg_match('/^\d+$/', $code)) {
            $skipped++;
            continue;
        }
        $rows[] = [
            'row_index' => $rowIndex + 1,
            'code' => $code,
            'portuguese_name' => trim($row[2] ?? ''),
            'english_name' => trim($row[3] ?? ''),
            'category_pt' => trim($row[4] ?? ''),
            'english_type' => trim($row[5] ?? ''),
            'sub_category_pt' => trim($row[6] ?? ''),
            'english_sub_type' => trim($row[7] ?? ''),
            'account_level' => $normalizeLevel($row[9] ?? ''),
            'description_pt' => trim($row[11] ?? ''),
            'description_en' => trim($row[12] ?? ''),
        ];
    }

    $companyQuery = User::where('type', 'company')->orderBy('id');
    if ($request->filled('company_id')) {
        $companyQuery->where('id', (int) $request->company_id);
    }
    $allCompanyIds = (clone $companyQuery)->pluck('id')->values()->all();
    $totalCompanies = count($allCompanyIds);

    $offset = max(0, (int) $request->query('offset', 0));
    $limit = (int) $request->query('limit', 0);
    if ($limit <= 0) {
        // Default: 1 company per request to avoid gateway timeout on large servers
        $limit = $request->filled('company_id') ? $totalCompanies : 1;
    }

    $companyIds = array_slice($allCompanyIds, $offset, $limit);
    if ($companyIds === []) {
        return response()->json([
            'message' => 'No companies in this batch (already finished or invalid offset).',
            'total_companies' => $totalCompanies,
            'offset' => $offset,
            'done' => true,
        ]);
    }

    $imported = 0;
    $companyResults = [];
    $errors = [];

    foreach ($companyIds as $companyId) {
        $company = User::find($companyId);
        if (!$company) {
            continue;
        }

        if ($request->boolean('skip_existing') && ChartOfAccount::where('created_by', $company->id)->where('code', '11')->exists()) {
            $companyResults[] = [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'status' => 'skipped',
                'reason' => 'Chart of accounts already present (code 11 exists).',
            ];
            continue;
        }

        $typeCache = ChartOfAccountType::where('created_by', $company->id)
            ->get()
            ->keyBy('name');
        $subTypeCache = [];
        foreach (ChartOfAccountSubType::where('created_by', $company->id)->get() as $st) {
            $subTypeCache[$st->type . '|' . $st->name] = $st;
        }
        $resolveType = static function (string $englishType, string $categoryPt, int $creatorId) use (&$typeCache) {
            if ($typeCache->has($englishType)) {
                return $typeCache->get($englishType);
            }
            $model = ChartOfAccountType::create([
                'name' => $englishType,
                'name_pt_br' => $categoryPt !== '' ? $categoryPt : $englishType,
                'created_by' => $creatorId,
            ]);
            $typeCache->put($englishType, $model);

            return $model;
        };

        $resolveSubType = static function (string $englishSubType, string $subCategoryPt, int $typeId, int $creatorId) use (&$subTypeCache) {
            $key = $typeId . '|' . $englishSubType;
            if (isset($subTypeCache[$key])) {
                return $subTypeCache[$key];
            }
            $model = ChartOfAccountSubType::create([
                'name' => $englishSubType,
                'name_pt_br' => $subCategoryPt !== '' ? $subCategoryPt : $englishSubType,
                'type' => $typeId,
                'created_by' => $creatorId,
            ]);
            $subTypeCache[$key] = $model;

            return $model;
        };

        $companyImported = 0;

        try {
            DB::transaction(function () use (
                $company,
                $rows,
                $resolveType,
                $resolveSubType,
                $parseDepth,
                $resolveParentCode,
                &$companyImported
            ) {
                $accountsByCode = [];
                $depthStack = [];

                foreach (ChartOfAccount::where('created_by', $company->id)->get(['id', 'code']) as $existingRow) {
                    $accountsByCode[$existingRow->code] = [
                        'id' => $existingRow->id,
                    ];
                }

                foreach ($rows as $row) {
                    $code = $row['code'];
                    $englishName = $row['english_name'];
                    $englishTypeName = $row['english_type'];
                    $englishSubTypeName = $row['english_sub_type'];
                    $accountLevel = $row['account_level'];
                    $depth = $parseDepth($accountLevel);
                    $canHaveChildren = str_contains($accountLevel, '- parent');

                    if ($englishName === '' || $englishTypeName === '' || $englishSubTypeName === '') {
                        throw new \Exception("Row {$row['row_index']} (code {$code}): missing name, category, or sub-category.");
                    }

                    $typeModel = $resolveType($englishTypeName, $row['category_pt'], $company->id);
                    $subType = $resolveSubType($englishSubTypeName, $row['sub_category_pt'], $typeModel->id, $company->id);

                    $accountPayload = [
                        'code' => $code,
                        'name' => $englishName,
                        'name_pt_br' => $row['portuguese_name'] !== '' ? $row['portuguese_name'] : $englishName,
                        'type' => $typeModel->id,
                        'sub_type' => $subType->id,
                        'description' => $row['description_en'],
                        'description_pt_br' => $row['description_pt'] !== '' ? $row['description_pt'] : $row['description_en'],
                        'is_enabled' => 1,
                        'created_by' => $company->id,
                    ];

                    $parentCode = $resolveParentCode($code, $depth, $accountsByCode, $depthStack);
                    $isTopParent = $depth === 0;

                    if ($isTopParent) {
                    $existing = ChartOfAccount::where('created_by', $company->id)->where('code', $code)->first();
                    if ($existing) {
                        $existing->fill(array_merge($accountPayload, [
                            'parent' => 0,
                            'parent_account_id' => null,
                        ]));
                        $existing->save();
                        $account = $existing;
                    } else {
                        $account = ChartOfAccount::create(array_merge($accountPayload, [
                            'parent' => 0,
                            'parent_account_id' => null,
                        ]));
                    }

                    ChartOfAccountParent::updateOrCreate(
                        ['account' => $account->id, 'created_by' => $company->id],
                        [
                            'name' => $account->name,
                            'name_pt_br' => $account->name_pt_br,
                            'type' => $typeModel->id,
                            'sub_type' => $subType->id,
                        ]
                    );

                        $accountsByCode[$code] = [
                            'id' => $account->id,
                            'depth' => 0,
                            'can_have_children' => $canHaveChildren,
                        ];
                        $depthStack[0] = $code;
                        foreach (array_keys($depthStack) as $stackDepth) {
                            if ($stackDepth > 0) {
                                unset($depthStack[$stackDepth]);
                            }
                        }
                        $companyImported++;

                        continue;
                    }

                    if ($parentCode === null || !isset($accountsByCode[$parentCode]['id'])) {
                        throw new \Exception("Row {$row['row_index']} (code {$code}, {$englishName}): could not resolve parent for level '{$accountLevel}'.");
                    }

                    $parentAccountId = (int) $accountsByCode[$parentCode]['id'];
                    $parentAccount = ChartOfAccount::find($parentAccountId);
                    if (! $parentAccount) {
                        throw new \Exception("Row {$row['row_index']} (code {$code}, {$englishName}): parent account {$parentCode} not found.");
                    }

                    $legacyParentId = ChartOfAccountHierarchy::legacyParentRowId($parentAccountId);

                    $existing = ChartOfAccount::where('created_by', $company->id)->where('code', $code)->first();
                    if ($existing) {
                        $existing->fill(array_merge($accountPayload, [
                            'parent' => $legacyParentId,
                            'parent_account_id' => $parentAccountId,
                        ]));
                        $existing->save();
                        $account = $existing;
                    } else {
                        $account = ChartOfAccount::create(array_merge($accountPayload, [
                            'parent' => $legacyParentId,
                            'parent_account_id' => $parentAccountId,
                        ]));
                    }

                    $accountsByCode[$code] = [
                        'id' => $account->id,
                        'depth' => $depth,
                        'can_have_children' => $canHaveChildren,
                    ];
                    $depthStack[$depth] = $code;
                    foreach (array_keys($depthStack) as $stackDepth) {
                        if ($stackDepth > $depth) {
                            unset($depthStack[$stackDepth]);
                        }
                    }
                    $companyImported++;
                }
            });

            $imported += $companyImported;
            ChartOfAccountHierarchy::syncMissingAccountsFromCsv((int) $company->id);
            ChartOfAccountHierarchy::repairParentsByCodePrefix((int) $company->id);
            ChartOfAccountHierarchy::syncTypesFromParents((int) $company->id);
            $companyResults[] = [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'status' => 'ok',
                'imported' => $companyImported,
            ];
        } catch (\Throwable $e) {
            $errors[] = [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'error' => $e->getMessage(),
            ];
        }
    }

    $nextOffset = $offset + count($companyIds);
    $done = $nextOffset >= $totalCompanies;
    $nextUrl = $done ? null : url('/insert_new_data') . '?' . http_build_query(array_filter([
        'offset' => $nextOffset,
        'limit' => $limit,
        'skip_existing' => $request->boolean('skip_existing') ? '1' : null,
    ]));

    return response()->json([
        'message' => $done ? 'Import finished for all companies.' : 'Batch imported. Open next_url to continue.',
        'imported' => $imported,
        'skipped_csv_rows' => $skipped,
        'total_companies' => $totalCompanies,
        'batch_offset' => $offset,
        'batch_limit' => $limit,
        'next_offset' => $done ? null : $nextOffset,
        'next_url' => $nextUrl,
        'done' => $done,
        'companies' => $companyResults,
        'errors' => $errors,
    ], $errors !== [] && $imported === 0 ? 500 : 200);
});






///copy link
Route::get('/customer/invoice/{id}/', [InvoiceController::class, 'invoiceLink'])->name('invoice.link.copy');
Route::get('/vender/bill/{id}/', [BillController::class, 'invoiceLink'])->name('bill.link.copy');
Route::get('/vendor/purchase/{id}/', [PurchaseController::class, 'purchaseLink'])->name('purchase.link.copy');
Route::get('/customer/proposal/{id}/', [ProposalController::class, 'invoiceLink'])->name('proposal.link.copy');
Route::get('/customer/pos-receipt/{id}/', [PosController::class, 'receiptLink'])->name('pos.receipt.link')->middleware('signed');
Route::get('proposal/pdf/{id}', [ProposalController::class, 'proposal'])->name('proposal.pdf')->middleware(['XSS', 'revalidate']);

//================================= Invoice Payment Gateways  ====================================//

Route::post('/customer-pay-with-bank', [BankTransferPaymentController::class, 'customerPayWithBank'])->name('customer.pay.with.bank')->middleware(['XSS']);
Route::get('invoice/{id}/action', [BankTransferPaymentController::class, 'invoiceAction'])->name('invoice.action');
Route::post('invoice/{id}/changeaction', [BankTransferPaymentController::class, 'invoiceChangeStatus'])->name('invoice.changestatus');

Route::post('{id}/pay-with-paypal', [PaypalController::class, 'customerPayWithPaypal'])->name('customer.pay.with.paypal');
Route::get('{id}/get-payment-status/{amount}', [PaypalController::class, 'customerGetPaymentStatus'])->name('customer.get.payment.status')->middleware(['XSS']);

Route::post('/customer-pay-with-paystack', [PaystackPaymentController::class, 'customerPayWithPaystack'])->name('customer.pay.with.paystack')->middleware(['XSS']);
Route::get('/customer/paystack/{pay_id}/{invoice_id}', [PaystackPaymentController::class, 'getInvoicePaymentStatus'])->name('customer.paystack');

Route::post('/customer-pay-with-paytm', [PaytmPaymentController::class, 'customerPayWithPaytm'])->name('customer.pay.with.paytm')->middleware(['XSS']);
Route::post('/customer/paytm/{invoice}/{amount}', [PaytmPaymentController::class, 'getInvoicePaymentStatus'])->name('customer.paytm');

Route::post('/customer-pay-with-flaterwave', [FlutterwavePaymentController::class, 'customerPayWithFlutterwave'])->name('customer.pay.with.flaterwave')->middleware(['XSS']);
Route::get('/customer/flaterwave/{txref}/{invoice_id}', [FlutterwavePaymentController::class, 'getInvoicePaymentStatus'])->name('customer.flaterwave');

Route::post('/customer-pay-with-razorpay', [RazorpayPaymentController::class, 'customerPayWithRazorpay'])->name('customer.pay.with.razorpay')->middleware(['XSS']);
Route::get('/customer/razorpay/{txref}/{invoice_id}', [RazorpayPaymentController::class, 'getInvoicePaymentStatus'])->name('customer.razorpay');

Route::post('/customer-pay-with-mercado', [MercadoPaymentController::class, 'customerPayWithMercado'])->name('customer.pay.with.mercado')->middleware(['XSS']);
Route::get('/customer/mercado/{invoice}', [MercadoPaymentController::class, 'getInvoicePaymentStatus'])->name('customer.mercado');

Route::post('/customer-pay-with-mollie', [MolliePaymentController::class, 'customerPayWithMollie'])->name('customer.pay.with.mollie')->middleware(['XSS']);
Route::get('/customer/mollie/{invoice}/{amount}', [MolliePaymentController::class, 'getInvoicePaymentStatus'])->name('customer.mollie');

Route::post('/customer-pay-with-skrill', [SkrillPaymentController::class, 'customerPayWithSkrill'])->name('customer.pay.with.skrill')->middleware(['XSS']);
Route::get('/customer/skrill/{invoice}/{amount}', [SkrillPaymentController::class, 'getInvoicePaymentStatus'])->name('customer.skrill');

Route::post('/customer-pay-with-coingate', [CoingatePaymentController::class, 'customerPayWithCoingate'])->name('customer.pay.with.coingate')->middleware(['XSS']);
Route::get('/customer/coingate/{invoice}/{amount}', [CoingatePaymentController::class, 'getInvoicePaymentStatus'])->name('customer.coingate');

Route::post('/paymentwall', [PaymentWallPaymentController::class, 'invoicepaymentwall'])->name('invoice.paymentwallpayment')->middleware(['XSS']);
Route::post('/invoice-pay-with-paymentwall/{invoice}', [PaymentWallPaymentController::class, 'invoicePayWithPaymentwall'])->name('invoice.pay.with.paymentwall')->middleware(['XSS']);
Route::get('/invoices/{flag}/{invoice}', [PaymentWallPaymentController::class, 'invoiceerror'])->name('error.invoice.show');

Route::post('/customer-pay-with-toyyibpay', [ToyyibpayController::class, 'invoicepaywithtoyyibpay'])->name('customer.pay.with.toyyibpay');
Route::get('/customer/toyyibpay/{invoice}/{amount}', [ToyyibpayController::class, 'getInvoicePaymentStatus'])->name('customer.toyyibpay');

Route::post('invoice-with-payfast', [PayFastController::class, 'invoicePayWithPayFast'])->name('invoice.with.payfast');
Route::get('invoice-payfast-status/{success}', [PayFastController::class, 'invoicepayfaststatus'])->name('invoice.payfast.status');

Route::post('/customer-pay-with-iyzipay', [IyziPayController::class, 'invoicepaywithiyzipay'])->name('customer.pay.with.iyzipay');
Route::post('iyzipay/callback/{invoice}/{amount}', [IyzipayController::class, 'getInvoiceiyzipayCallback'])->name('iyzipay.invoicepayment.callback');

Route::post('/customer-pay-with-sspay', [SspayController::class, 'invoicepaywithsspaypay'])->name('customer.pay.with.sspay');
Route::get('/customer/sspay/{invoice}/{amount}', [SspayController::class, 'getInvoicePaymentStatus'])->name('customer.sspay');

Route::post('/invoice-pay-with-paytab', [PaytabController::class, 'invoicePayWithpaytab'])->name('customer.pay.with.paytab');
Route::any('/invoice-paytab-success/{invoice}', [PaytabController::class, 'getInvoicePaymentStatus'])->name('invoice.paytab.success');

Route::any('invoice-with-benefit', [BenefitPaymentController::class, 'invoicepaywithbenefit'])->name('invoice.benefit.initiate');
Route::any('/invoice/benefit/{invoice_id}/{amount}', [BenefitPaymentController::class, 'getInvoicePaymentStatus'])->name('invoice.benefit.call_back');

Route::post('invoice-with-cashfree', [CashfreeController::class, 'invoicepaywithcashfree'])->name('customer.pay.with.cashfree');
Route::any('invoice-with-cashfree/cashfree', [CashfreeController::class, 'getInvoicePaymentStatus'])->name('invoice.cashfreePayment.success');

Route::post('aamarpay/invoice/', [AamarpayController::class, 'invoicepaywithaamarpay'])->name('customer.pay.with.aamarpay');
Route::any('aamarpay/invoice/success/{data}', [AamarpayController::class, 'getInvoicePaymentStatus'])->name('invoice.pay.aamarpay.success');

Route::post('/invoice-with-paytr', [PaytrController::class, 'invoicepaywithpaytr'])->name('customer.pay.with.paytr');
Route::get('/invoice/paytr/status', [PaytrController::class, 'getInvoicePaymentStatus'])->name('invoice.paytr');

Route::post('invoice-with-yookassa/', [YooKassaController::class, 'invoicePayWithYookassa'])->name('customer.with.yookassa');
Route::any('invoice-yookassa-status/', [YooKassaController::class, 'getInvociePaymentStatus'])->name('invoice.yookassa.status');

Route::any('invoice-with-midtrans/', [MidtransPaymentController::class, 'invoicePayWithMidtrans'])->name('customer.with.midtrans');
Route::any('invoice-midtrans-status/', [MidtransPaymentController::class, 'getInvociePaymentStatus'])->name('invoice.midtrans.status');

Route::any('/invoice-with-xendit', [XenditPaymentController::class, 'invoicePayWithXendit'])->name('customer.with.xendit');
Route::any('/invoice-xendit-status', [XenditPaymentController::class, 'getInvociePaymentStatus'])->name('invoice.xendit.status');

Route::post('invoice-with-paiementpro/', [PaiementProController::class, 'invoicePayWithPaiementPro'])->name('customer.with.paiementpro');
Route::get('invoice-paiementpro-status/{invoice_id}', [PaiementProController::class, 'getInvociePaymentStatus'])->name('invoice.paiementpro.status');

Route::post('/invoice-nepalste/payment', [NepalstePaymnetController::class, 'invoicePayWithnepalste'])->name('customer.with.nepalste');
Route::get('invoice-nepalste/status/', [NepalstePaymnetController::class, 'invoiceGetNepalsteStatus'])->name('invoice.nepalste.status');
Route::get('invoice-nepalste/cancel/', [NepalstePaymnetController::class, 'invoiceGetNepalsteCancel'])->name('invoice.nepalste.cancel');

Route::post('/invoice/company/payment', [CinetPayController::class, 'invoicePayWithCinetPay'])->name('customer.with.cinetpay');
Route::post('/invoice/company/payment/return', [CinetPayController::class, 'invoiceCinetPayReturn'])->name('invoice.cinetpay.return');
Route::post('/invoice/company/payment/notify/', [CinetPayController::class, 'invoiceCinetPayNotify'])->name('invoice.cinetpay.notify');

Route::post('invoice-with-fedapay/', [FedapayController::class, 'invoicePayWithFedapay'])->name('customer.with.fedapay');
Route::get('invoice-fedapay-status/', [FedapayController::class, 'getInvociePaymentStatus'])->name('invoice.fedapay.status');

Route::post('invoice-with-payhere/', [PayHereController::class, 'invoicePayWithPayHere'])->name('customer.with.payhere');
Route::get('invoice-payhere-status/', [PayHereController::class, 'getInvociePaymentStatus'])->name('invoice.payhere.status');

Route::post('/invoice-pay-with/tap',[TapController::class,'invoicePayWithtap'])->name('invoice.pay.with.tap');
Route::get('/invoice/tap/{invoice_id}/{amount}',[TapController::class,'getInvoicePaymentStatus'])->name('invoice.tap');

Route::any('/invoice-pay-with-authorize-net', [AuthorizeNetController::class, 'invoicePayWithAuthorizeNet'])->name('invoice.pay.with.authorizenet');
Route::any('/invoice-get-authorizenet-status',[AuthorizeNetController::class,'getInvoicePaymentStatus'])->name('invoice.get.authorizenet.status');

Route::post('/invoice-pay-khalti',[KhaltiController::class,'getInvoicePaymentStatus'])->name('invoice.pay.with.khalti');

Route::any('/invoice-pay-easebuzz', [EasebuzzController::class, 'invoicePayWitheasebuzz'])->name('invoice.pay.with.easebuzz');
Route::match(['get','post'],'/invoice-easebuzz-payment-return', [EasebuzzController::class,'invoiceNotifyUrl'])->name('invoice.easebuzz.return');
Route::match(['get','post'],'invoice-easebuzz-payment-notify', [EasebuzzController::class,'invoiceReturnUrl'])->name('invoice.get.easebuzz.notify');

Route::post('/invoice-pay-ozow', [OzowPaymentController::class, 'invoicePayWithOzow'])->name('invoice.pay.with.ozow');
Route::get('/invoice-pay-ozow/{invoice_id}/',[OzowPaymentController::class,'getInvoicePaymentStatus'])->name('invoice.ozow.status');


/***********************************************************************************************************************************************/

//career page
Route::get('career/{id}/{lang}', [JobController::class, 'career'])->name('career')->middleware(['XSS']);
Route::get('job/requirement/{code}/{lang}', [JobController::class, 'jobRequirement'])->name('job.requirement')->middleware(['XSS']);
Route::get('job/apply/{code}/{lang}', [JobController::class, 'jobApply'])->name('job.apply')->middleware(['XSS']);
Route::post('job/apply/data/{code}', [JobController::class, 'jobApplyData'])->name('job.apply.data')->middleware(['XSS']);

//project copy module
Route::get('/projects/copylink/{id}', [ProjectController::class, 'projectCopyLink'])->name('projects.copylink');
Route::any('/projects/link/{id}/{lang?}', [ProjectController::class, 'projectlink'])->name('projects.link')->middleware(['XSS']);
Route::get('timesheet-table-view', [TimesheetController::class, 'filterTimesheetTableView'])->name('filter.timesheet.table.view')->middleware(['auth', 'XSS']);

// Invoice Payment Gateways
Route::post('customer/{id}/payment', [StripePaymentController::class, 'addpayment'])->name('customer.payment');
Route::get('invoice/pdf/{id}', [InvoiceController::class, 'invoice'])->name('invoice.pdf')->middleware(['XSS', 'revalidate']);

Route::get('users/{id}/login-with-company', [UserController::class, 'LoginWithCompany'])->name('login.with.company')->middleware(['auth']);
Route::post('users/{id}/force-verify-email', [UserController::class, 'forceVerifyEmail'])->name('users.force.verify')->middleware(['auth']);
Route::get('login-with-company/exit', [UserController::class, 'ExitCompany'])->name('exit.company')->middleware(['auth']);
Route::get('user-login/{id}', [UserController::class, 'LoginManage'])->name('users.login');

Route::get('/form/{code}', [FormBuilderController::class, 'formView'])->name('form.view')->middleware(['XSS']);
Route::post('/form_view_store', [FormBuilderController::class, 'formViewStore'])->name('form.view.store')->middleware(['XSS']);

Route::get('/', [DashboardController::class, 'landingpage'])->name('landingpage.home')->middleware(['XSS', 'revalidate']);


// cache
Route::get('/config-cache', function () {
    Artisan::call('cache:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    Artisan::call('optimize:clear');
    return redirect()->back()->with('success', 'Cache Clear Successfully');
})->name('config.cache');

/**
 * One-time bulk stock: company 79, warehouse 51, +10 per product (same as productstock manual update).
 * Open: /bulk-add-product-stock  (optional: ?company=79&warehouse=51&qty=10)
 * Remove this route after use.
 */
Route::get('/bulk-add-product-stock', function (\Illuminate\Http\Request $request) {
    @set_time_limit(0);

    $companyId = (int) $request->query('company', 79);
    $warehouseId = (int) $request->query('warehouse', 51);
    $qtyToAdd = (float) $request->query('qty', 10);

    if ($companyId < 1 || $warehouseId < 1 || $qtyToAdd <= 0) {
        return response()->json(['error' => 'Invalid company, warehouse, or qty'], 422);
    }

    $companyUser = User::where('id', $companyId)->where('type', 'company')->first();
    if (! $companyUser) {
        return response()->json(['error' => "Company user id {$companyId} not found"], 404);
    }

    $warehouse = warehouse::where('id', $warehouseId)->where('created_by', $companyId)->first();
    if (! $warehouse) {
        return response()->json(['error' => "Warehouse id {$warehouseId} not found for company {$companyId}"], 404);
    }

    Auth::login($companyUser);

    $products = ProductService::where('created_by', $companyId)->where('type', 'product')->get();

    $updated = [];
    $skipped = [];

    foreach ($products as $productService) {
        if ($productService->variants_enabled && $productService->variants()->count() > 0) {
            $skipped[] = [
                'id' => $productService->id,
                'name' => $productService->name,
                'reason' => 'variants enabled — update manually per variant',
            ];

            continue;
        }

        if ($productService->identifier_tracking) {
            $skipped[] = [
                'id' => $productService->id,
                'name' => $productService->name,
                'reason' => 'serial/MAC tracking — needs identifiers',
            ];

            continue;
        }

        Utility::addWarehouseStock((int) $productService->id, $qtyToAdd, $warehouseId);

        $productService->quantity = ($productService->quantity ?? 0) + $qtyToAdd;
        $productService->save();

        $description = $qtyToAdd . '  ' . __('quantity added by manually') . ' (bulk script)';
        Utility::addProductStock($productService->id, $qtyToAdd, 'manually', $description, 0);

        $updated[] = [
            'id' => $productService->id,
            'name' => $productService->name,
            'total_quantity' => $productService->quantity,
        ];
    }

    Auth::logout();

    return response()->json([
        'success' => true,
        'company' => $companyId,
        'warehouse' => $warehouseId,
        'qty_added_per_product' => $qtyToAdd,
        'updated_count' => count($updated),
        'skipped_count' => count($skipped),
        'updated' => $updated,
        'skipped' => $skipped,
    ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
});


//================================= Invoice Payment Gateways  ====================================//
Route::group(['middleware' => ['verified']], function () {

    // Route::get('/home', [DashboardController::class, 'account_dashboard_index'])->name('home')->middleware(['XSS', 'revalidate']);

    // Route::get('/dashboard', [DashboardController::class, 'show_dashboard'])->name('dashboard')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('/account-dashboard', [DashboardController::class, 'account_dashboard_index'])->name('dashboard')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('/project-dashboard', [DashboardController::class, 'project_dashboard_index'])->name('project.dashboard')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('/hrm-dashboard', [DashboardController::class, 'hrm_dashboard_index'])->name('hrm.dashboard')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('/crm-dashboard', [DashboardController::class, 'crm_dashboard_index'])->name('crm.dashboard')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('/sales-dashboard', [DashboardController::class, 'sales_dashboard_index'])->name('sales.dashboard')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('/pos-dashboard', [DashboardController::class, 'pos_dashboard_index'])->name('pos.dashboard')->middleware(['auth', 'XSS', 'revalidate']);

    Route::get('profile', [UserController::class, 'profile'])->name('profile')->middleware(['auth', 'XSS', 'revalidate']);

    Route::any('edit-profile', [UserController::class, 'editprofile'])->name('update.account')->middleware(['auth', 'XSS', 'revalidate']);

    Route::post('user/branch-context', [UserController::class, 'setBranchContext'])->name('user.branch-context')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('user/company-context', [UserController::class, 'setCompanyContext'])->name('user.company-context')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('users/{id}/company-memberships', [UserController::class, 'updateUserCompanyMemberships'])->name('users.company-memberships')->middleware(['auth', 'XSS', 'revalidate']);

    Route::resource('users', UserController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::post('change-password', [UserController::class, 'updatePassword'])->name('update.password');
    Route::post('2fa/enable', [UserController::class, 'enableTwoFactor'])->name('2fa.enable')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('2fa/disable', [UserController::class, 'disableTwoFactor'])->name('2fa.disable')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('2fa/recovery-codes', [UserController::class, 'regenerateTwoFactorRecoveryCodes'])->name('2fa.recovery.regenerate')->middleware(['auth', 'XSS', 'revalidate']);

    Route::any('user-reset-password/{id}', [UserController::class, 'userPassword'])->name('users.reset');

    Route::post('user-reset-password/{id}', [UserController::class, 'userPasswordReset'])->name('user.password.update');
    Route::get('company-info/{id}', [UserController::class, 'companyInfo'])->name('company.info');
    Route::post('user-unable', [UserController::class, 'userUnable'])->name('user.unable');

    Route::get('/change/mode', [UserController::class, 'changeMode'])->name('change.mode');

    Route::resource('roles', RoleController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::resource('permissions', PermissionController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('change-language/{lang}', [LanguageController::class, 'changeLanquage'])->name('change.language');

            Route::get('manage-language/{lang}', [LanguageController::class, 'manageLanguage'])->name('manage.language');

            Route::post('store-language-data/{lang}', [LanguageController::class, 'storeLanguageData'])->name('store.language.data');

            Route::get('create-language', [LanguageController::class, 'createLanguage'])->name('create.language');

            Route::any('store-language', [LanguageController::class, 'storeLanguage'])->name('store.language');

            Route::delete('/lang/{lang}', [LanguageController::class, 'destroyLang'])->name('lang.destroy');
        }
    );

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::resource('systems', SystemController::class);
            Route::post('email-settings', [SystemController::class, 'saveEmailSettings'])->name('email.settings');
            Route::post('company-email-settings', [SystemController::class, 'saveCompanyEmailSettings'])->name('company.email.settings');

            Route::post('company-settings', [SystemController::class, 'saveCompanySettings'])->name('company.settings');
            Route::post('system-settings', [SystemController::class, 'saveSystemSettings'])->name('system.settings');
            Route::post('zoom-settings', [SystemController::class, 'saveZoomSettings'])->name('zoom.settings');
            Route::post('tracker-settings', [SystemController::class, 'saveTrackerSettings'])->name('tracker.settings');
            Route::post('slack-settings', [SystemController::class, 'saveSlackSettings'])->name('slack.settings');
            Route::post('telegram-settings', [SystemController::class, 'saveTelegramSettings'])->name('telegram.settings');
            Route::post('twilio-settings', [SystemController::class, 'saveTwilioSettings'])->name('twilio.setting');
            Route::get('print-setting', [SystemController::class, 'printIndex'])->name('print.setting');
            Route::get('document-layouts', [DocumentLayoutController::class, 'index'])->name('document-layouts.index');
            Route::get('document-layouts/create', [DocumentLayoutController::class, 'create'])->name('document-layouts.create');
            Route::post('document-layouts', [DocumentLayoutController::class, 'store'])->name('document-layouts.store');
            Route::get('document-layouts/preview/{id?}', [DocumentLayoutController::class, 'preview'])->name('document-layouts.preview');
            Route::get('document-layouts/{id}/edit', [DocumentLayoutController::class, 'edit'])->name('document-layouts.edit');
            Route::put('document-layouts/{id}', [DocumentLayoutController::class, 'update'])->name('document-layouts.update');
            Route::delete('document-layouts/{id}', [DocumentLayoutController::class, 'destroy'])->name('document-layouts.destroy');
            Route::post('document-layouts/{id}/activate', [DocumentLayoutController::class, 'activate'])->name('document-layouts.activate');
            Route::post('document-layouts/{id}/deactivate', [DocumentLayoutController::class, 'deactivate'])->name('document-layouts.deactivate');
            Route::get('settings', [SystemController::class, 'companyIndex'])->name('settings')->middleware(['XSS']);
            Route::post('business-setting', [SystemController::class, 'saveBusinessSettings'])->name('business.setting');
            Route::post('company-payment-setting', [SystemController::class, 'saveCompanyPaymentSettings'])->name('company.payment.settings');
            Route::post('currency-settings', [SystemController::class, 'saveCurrencySettings'])->name('currency.settings');
            Route::post('company-preview', [SystemController::class, 'currencyPreview'])->name('currency.preview');


            Route::any('test-mail', [SystemController::class, 'testMail'])->name('test.mail');
            Route::post('test-mail/send', [SystemController::class, 'testSendMail'])->name('test.send.mail');

            Route::post('stripe-settings', [SystemController::class, 'savePaymentSettings'])->name('payment.settings');
            Route::post('pusher-setting', [SystemController::class, 'savePusherSettings'])->name('pusher.setting');
            Route::post('recaptcha-settings', [SystemController::class, 'recaptchaSettingStore'])->name('recaptcha.settings.store')->middleware(['auth', 'XSS']);

            Route::post('seo-settings', [SystemController::class, 'seoSettings'])->name('seo.settings.store')->middleware(['auth', 'XSS']);
            Route::any('webhook-settings', [SystemController::class, 'webhook'])->name('webhook.settings')->middleware(['auth', 'XSS']);
            Route::get('webhook-settings/create', [SystemController::class, 'webhookCreate'])->name('webhook.create')->middleware(['auth', 'XSS']);
            Route::post('webhook-settings/store', [SystemController::class, 'webhookStore'])->name('webhook.store');
            Route::get('webhook-settings/{wid}/edit', [SystemController::class, 'webhookEdit'])->name('webhook.edit')->middleware(['auth', 'XSS']);
            Route::post('webhook-settings/{wid}/edit', [SystemController::class, 'webhookUpdate'])->name('webhook.update')->middleware(['auth', 'XSS']);
            Route::delete('webhook-settings/{wid}', [SystemController::class, 'webhookDestroy'])->name('webhook.destroy')->middleware(['auth', 'XSS']);

            Route::post('cookie-setting', [SystemController::class, 'saveCookieSettings'])->name('cookie.setting');

            Route::post('cache-settings', [SystemController::class, 'cacheSettingStore'])->name('cache.settings.store')->middleware(['auth', 'XSS']);
            Route::get('/backup/create', [SystemController::class, 'createDatabaseBackup'])->name('backup.create');
            Route::get('/backup/download/{file}', [SystemController::class, 'downloadBackup'])->name('backup.download');
            
        }
    );

    Route::match(['get', 'post'], 'productservice/datatable', [ProductServiceController::class, 'datatable'])->name('productservice.datatable')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('productservice/generate-sku', [ProductServiceController::class, 'generateSku'])->name('productservice.generate-sku')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('productservice/{productservice}/variants-json', [ProductServiceController::class, 'variantsJson'])->name('productservice.variants-json')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('productservice/{productservice}/options-json', [ProductServiceController::class, 'optionsJson'])->name('productservice.options-json')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('productservice/{id}/show-on-pos', [ProductServiceController::class, 'toggleShowOnPos'])->name('productservice.show-on-pos')->middleware(['auth', 'XSS']);
    Route::post('productservice/{id}/affect-stock', [ProductServiceController::class, 'toggleAffectStock'])->name('productservice.affect-stock')->middleware(['auth', 'XSS']);
    Route::get('productservice/{id}/detail', [ProductServiceController::class, 'warehouseDetail'])->name('productservice.detail');
    Route::get('productservice/{id}/stock-status', [ProductServiceController::class, 'stockStatus'])->name('productservice.stock-status');
    Route::post('productservice/{id}/stock-transfer', [ProductServiceController::class, 'stockTransfer'])->name('productservice.stock-transfer')->middleware(['auth', 'XSS']);
    Route::get('productservice/{id}/warehouse-stock-alerts', [ProductServiceController::class, 'warehouseStockAlerts'])->name('productservice.warehouse-stock-alerts');
    Route::post('productservice/{id}/warehouse-stock-alerts', [ProductServiceController::class, 'warehouseStockAlertsStore'])->name('productservice.warehouse-stock-alerts.store')->middleware(['auth', 'XSS']);
    Route::post('low-stock-notifications/{id}/read', [LowStockNotificationController::class, 'markRead'])->name('low-stock-notifications.read')->middleware(['auth', 'XSS']);
    Route::post('low-stock-notifications/read-all', [LowStockNotificationController::class, 'markAllRead'])->name('low-stock-notifications.read-all')->middleware(['auth', 'XSS']);
    Route::post('productservice/{id}/kit-items', [ProductServiceController::class, 'kitItemStore'])->name('productservice.kit-items.store')->middleware(['auth', 'XSS']);
    Route::delete('productservice/{id}/kit-items/{kit_item}', [ProductServiceController::class, 'kitItemDestroy'])->name('productservice.kit-items.destroy')->middleware(['auth', 'XSS']);
    Route::post('empty-cart', [ProductServiceController::class, 'emptyCart'])->middleware(['auth', 'XSS']);
    Route::post('warehouse-empty-cart', [ProductServiceController::class, 'warehouseemptyCart'])->name('warehouse-empty-cart')->middleware(['auth', 'XSS']);
    Route::resource('productservice', ProductServiceController::class)->middleware(['auth', 'XSS', 'revalidate']);

    //Product Stock
    Route::get('product-batches', [ProductBatchMonitorController::class, 'index'])->name('product-batches.index')->middleware(['auth', 'XSS']);
    Route::get('productstock/print', [ProductStockController::class, 'printStock'])->name('productstock.print')->middleware(['auth', 'XSS']);
    Route::get('productstock/{id}/print', [ProductStockController::class, 'printIntroduction'])->name('productstock.print-intro')->middleware(['auth', 'XSS']);
    Route::resource('productstock', ProductStockController::class)->middleware(['auth', 'XSS']);

    //Customer
    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::resource('customer', CustomerController::class);
            Route::post('customer/store-from-pos', [CustomerController::class, 'storeFromPos'])->name('customer.store.from.pos');
        }
    );

    //Vendor
    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::resource('vender', VenderController::class);
        }
    );

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::resource('bank-account', BankAccountController::class);
        }
    );

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::resource('bank-transfer', BankTransferController::class);
        }
    );

    Route::resource('taxes', TaxController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('price-levels', PriceLevelController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::resource('product-category', ProductServiceCategoryController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::post('product-category/getaccount', [ProductServiceCategoryController::class, 'getAccount'])->name('productServiceCategory.getaccount')->middleware(['auth', 'XSS', 'revalidate']);

    Route::resource('product-unit', ProductServiceUnitController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('invoice/{id}/duplicate', [InvoiceController::class, 'duplicate'])->name('invoice.duplicate');
            Route::get('invoice/{id}/shipping/print', [InvoiceController::class, 'shippingDisplay'])->name('invoice.shipping.print');
            Route::get('invoice/{id}/payment/reminder', [InvoiceController::class, 'paymentReminder'])->name('invoice.payment.reminder');
            Route::post('invoice/product/destroy', [InvoiceController::class, 'productDestroy'])->name('invoice.product.destroy');
            Route::post('invoice/product', [InvoiceController::class, 'product'])->name('invoice.product');
            Route::get('invoice/product-identifiers', [InvoiceController::class, 'productIdentifiers'])->name('invoice.product.identifiers');
            Route::post('invoice/customer', [InvoiceController::class, 'customer'])->name('invoice.customer');
            Route::post('invoice/customer/addresses', [InvoiceController::class, 'customer_addresses'])->name('invoice.customer.addresses');
            Route::post('invoice/customer/specific/address', [InvoiceController::class, 'customer_address_detail'])->name('invoice.customer.specific.address');
            Route::get('invoice/{id}/sent', [InvoiceController::class, 'sent'])->name('invoice.sent');
            Route::get('invoice/{id}/authorize', [InvoiceController::class, 'authorizeInvoice'])->name('invoice.authorize');
            Route::get('invoice/{id}/reject-authorization', [InvoiceController::class, 'rejectAuthorization'])->name('invoice.reject.authorization');
            Route::get('invoice/{id}/resent', [InvoiceController::class, 'resent'])->name('invoice.resent');
            Route::get('invoice/{id}/payment', [InvoiceController::class, 'payment'])->name('invoice.payments');
            Route::post('invoice/{id}/payment', [InvoiceController::class, 'createPayment'])->name('invoice.payment');
            Route::post('invoice/{id}/payment/{pid}/destroy', [InvoiceController::class, 'paymentDestroy'])->name('invoice.payment.destroy');
            Route::get('invoice/items', [InvoiceController::class, 'items'])->name('invoice.items');
            Route::post('invoice/{id}/delivery-note', [DeliveryNoteController::class, 'createFromInvoice'])->name('invoice.delivery-note.create');
            Route::resource('invoice', InvoiceController::class);
            Route::get('invoice/create/{cid}', [InvoiceController::class, 'create'])->name('invoice.create');

            Route::get('delivery-note/{id}', [DeliveryNoteController::class, 'show'])->name('delivery-note.show');
            Route::get('delivery-note/{id}/print', [DeliveryNoteController::class, 'print'])->name('delivery-note.print');

            Route::post('delivery-note/{id}/convert-to-manifest', [RoadManifestController::class, 'convertFromDeliveryNote'])->name('delivery-note.convert-to-manifest');
            Route::get('road-manifest/{id}', [RoadManifestController::class, 'show'])->name('road-manifest.show');
            Route::get('road-manifest/{id}/print', [RoadManifestController::class, 'print'])->name('road-manifest.print');

            Route::get('road-transport-record/create/{manifestId?}', [RoadTransportRecordController::class, 'create'])->name('road-transport-record.create');
            Route::post('road-transport-record', [RoadTransportRecordController::class, 'store'])->name('road-transport-record.store');
            Route::get('road-transport-record/{id}', [RoadTransportRecordController::class, 'show'])->name('road-transport-record.show');

        }
    );

    Route::get('/invoices/preview/{template}/{color}', [InvoiceController::class, 'previewInvoice'])->name('invoice.preview');
    Route::post('/invoices/template/setting', [InvoiceController::class, 'saveTemplateSettings'])->name('template.setting');

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('credit-note', [CreditNoteController::class, 'index'])->name('credit.note');
            Route::get('sale-debit-note', [CreditNoteController::class, 'sale_debit_notes'])->name('sale.debit.note');
            Route::get('create-sale-debit-note', [CreditNoteController::class, 'create_sale_debit_notes'])->name('create.sale.debit.note');
            Route::post('create-sale-debit-note', [CreditNoteController::class, 'save_sale_debit_notes'])->name('create.sale.debit.note');
            Route::get('custom-debit-note', [CreditNoteController::class, 'customCreate'])->name('invoice.custom.credit.notes');
            Route::post('custom-debit-note', [CreditNoteController::class, 'customStore'])->name('invoice.custom.credit.note');
            Route::get('debit-note/invoice', [CreditNoteController::class, 'getinvoice'])->name('invoice.get');
            Route::get('invoice/{id}/debit-note', [CreditNoteController::class, 'create'])->name('saas-');
            Route::post('invoice/{id}/debit-note', [CreditNoteController::class, 'store'])->name('invoice.credit.note');
            Route::get('invoice/{id}/debit-notes/edit/{cn_id}', [CreditNoteController::class, 'edit'])->name('invoice.edit.credit.notes');
            Route::post('invoice/{id}/debit-note/edit/{cn_id}', [CreditNoteController::class, 'update'])->name('invoice.edit.credit.note');
            Route::delete('invoice/{id}/debit-note/delete/{cn_id}', [CreditNoteController::class, 'destroy'])->name('invoice.delete.credit.note');
        }
    );

    // Invoice
    Route::get('get-items', [CreditNoteController::class, 'getItems'])->name('invoice.get.items');
    Route::get('note-view/{id}', [CreditNoteController::class, 'view'])->name('note.view');
    Route::get('debit-note-view/{id}', [CreditNoteController::class, 'debitView'])->name('debit.note.view');
    
    // Bill
    Route::get('bill-get-items', [BillCreditNoteController::class, 'getItems'])->name('bill.get.items');
    Route::get('bill-note-view/{id}', [BillCreditNoteController::class, 'view'])->name('bill.note.view');
    Route::get('bill-debit-note-view/{id}', [DebitNoteController::class, 'debitView'])->name('bill.debit.note.view');
    
    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('debit-note', [DebitNoteController::class, 'index'])->name('debit.note');
            Route::get('custom-credit-notes', [DebitNoteController::class, 'customCreate'])->name('bill.custom.debit.notes');
            Route::post('custom-credit-note', [DebitNoteController::class, 'customStore'])->name('bill.custom.debit.note');
            Route::get('credit-note/bill', [DebitNoteController::class, 'getbill'])->name('bill.get');
            Route::get('bill/{id}/credit-notes', [DebitNoteController::class, 'create'])->name('bill.debit.notes');
            Route::post('bill/{id}/credit-note', [DebitNoteController::class, 'store'])->name('bill.debit.note');
            Route::get('bill/{id}/credit-notes/edit/{cn_id}', [DebitNoteController::class, 'edit'])->name('bill.edit.debit.notes');
            Route::post('bill/{id}/credit-note/edit/{cn_id}', [DebitNoteController::class, 'update'])->name('bill.edit.debit.note');
            Route::delete('bill/{id}/credit-note/delete/{cn_id}', [DebitNoteController::class, 'destroy'])->name('bill.delete.debit.note');
        }
    );
    
    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('bill-credit-note', [BillCreditNoteController::class, 'index'])->name('bill.credit.note');
            Route::get('custom-bill-credit-notes', [BillCreditNoteController::class, 'customCreate'])->name('bill.custom.credit.notes');
            Route::post('custom-bill-credit-note', [BillCreditNoteController::class, 'customStore'])->name('bill.custom.credit.note');
            Route::get('bill/{id}/bill-credit-notes/edit/{cn_id}', [BillCreditNoteController::class, 'edit'])->name('bill.edit.credit.notes');
            Route::post('bill/{id}/bill-credit-note/edit/{cn_id}', [BillCreditNoteController::class, 'update'])->name('bill.edit.credit.note');
            Route::delete('bill/{id}/bill-credit-note/delete/{cn_id}', [BillCreditNoteController::class, 'destroy'])->name('bill.delete.credit.note');
        }
    );

    Route::get('/bill/preview/{template}/{color}', [BillController::class, 'previewBill'])->name('bill.preview')->middleware(['auth', 'XSS']);
    Route::post('/bill/template/setting', [BillController::class, 'saveBillTemplateSettings'])->name('bill.template.setting');

    Route::resource('taxes', TaxController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('price-levels', PriceLevelController::class)->middleware(['auth', 'XSS', 'revalidate']);


    Route::resource('revenue', RevenueController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::get('bill/pdf/{id}', [BillController::class, 'bill'])->name('bill.pdf')->middleware(['XSS', 'revalidate']);

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('bill/{id}/duplicate', [BillController::class, 'duplicate'])->name('bill.duplicate');
            Route::get('bill/{id}/shipping/print', [BillController::class, 'shippingDisplay'])->name('bill.shipping.print');
            Route::post('bill/product/destroy', [BillController::class, 'productDestroy'])->name('bill.product.destroy');
            Route::post('bill/product', [BillController::class, 'product'])->name('bill.product');
            Route::post('bill/vender', [BillController::class, 'vender'])->name('bill.vender');
            Route::get('bill/{id}/sent', [BillController::class, 'sent'])->name('bill.sent');
            Route::get('bill/{id}/resent', [BillController::class, 'resent'])->name('bill.resent');
            Route::get('bill/{id}/payments', [BillController::class, 'payment'])->name('bill.payments');
            Route::post('bill/{id}/payment', [BillController::class, 'createPayment'])->name('bill.payment');
            Route::post('bill/{id}/payment/{pid}/destroy', [BillController::class, 'paymentDestroy'])->name('bill.payment.destroy');
            Route::get('bill/items', [BillController::class, 'items'])->name('bill.items');
            Route::resource('bill', BillController::class);
        }
    );


    Route::resource('payment', PaymentController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('report/transaction', [TransactionController::class, 'index'])->name('transaction.index');
        }
    );

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('report/income-summary', [ReportController::class, 'incomeSummary'])->name('report.income.summary');
            Route::get('report/expense-summary', [ReportController::class, 'expenseSummary'])->name('report.expense.summary');
            Route::get('report/income-vs-expense-summary', [ReportController::class, 'incomeVsExpenseSummary'])->name('report.income.vs.expense.summary');
            Route::get('report/tax-summary', [ReportController::class, 'taxSummary'])->name('report.tax.summary');
            //        Route::get('report/profit-loss-summary', [ReportController::class, 'profitLossSummary'])->name('report.profit.loss.summary');
            Route::get('report/invoice-summary', [ReportController::class, 'invoiceSummary'])->name('report.invoice.summary');
            Route::get('report/bill-summary', [ReportController::class, 'billSummary'])->name('report.bill.summary');
            Route::get('report/product-stock-report', [ReportController::class, 'productStock'])->name('report.product.stock.report');
            Route::get('report/minimum-stock-alert', function () {
                return redirect()->route('reports.product.minimum-stock-alert', request()->query());
            })->name('report.minimum.stock.alert');
            Route::get('report/minimum-stock-alert/export', function () {
                return redirect()->route('reports.product.minimum-stock-alert.export', request()->query());
            })->name('report.minimum.stock.alert.export');
            Route::get('report/invoice-report', [ReportController::class, 'invoiceReport'])->name('report.invoice');
            Route::get('report/account-statement-report', [ReportController::class, 'accountStatement'])->name('report.account.statement');
            Route::get('balance-sheet-report/{view?}/{collapseview?}', [ReportController::class, 'balanceSheet'])->name('report.balance.sheet');
            Route::get('profit-loss-report/{view?}/{collapseView?}', [ReportController::class, 'profitLoss'])->name('report.profit.loss');
            Route::get('reports-demonstracao-resultados', [DreReportController::class, 'index'])->name('report.dre.comparativa');
            Route::get('export/dre-demonstracao-resultados', [DreReportController::class, 'export'])->name('report.dre.comparativa.export');

            Route::get('ledger-report/{account?}', [ReportController::class, 'ledgerSummary'])->name('report.ledger');
            Route::get('trial-balance-report/{view?}', [ReportController::class, 'trialBalanceSummary'])->name('trial.balance');

            // Report sections (navigation / grouping)
            Route::get('reports/monthly', [ReportSectionsController::class, 'monthly'])->name('reports.sections.monthly');
            Route::get('reports/management', [ReportSectionsController::class, 'management'])->name('reports.sections.management');
            Route::get('reports/analytical', [ReportSectionsController::class, 'analytical'])->name('reports.sections.analytical');
            Route::get('reports/analytical/powerblox', [AnalyticalReportController::class, 'powerblox'])->name('reports.analytical.powerblox');

            // Credit and Debit Note Reports
            Route::get('reports/credit-note', [CreditDebitNoteReportsController::class, 'creditNoteReport'])->name('reports.credit.note');
            Route::get('reports/debit-note', [CreditDebitNoteReportsController::class, 'debitNoteReport'])->name('reports.debit.note');

            // Government/statutory format (editable Blade template)
            Route::get('monthly-reports/trial-balance/{view?}', [MonthlyReportController::class, 'trialBalance'])
                ->name('monthly.reports.trial-balance');

            Route::get('monthly-reports/vat-modelo-a', [MonthlyVatReportController::class, 'modeloA'])
                ->name('monthly.reports.vat-modelo-a');
            Route::get('monthly-reports/vat-modelo-a/export', [MonthlyVatReportController::class, 'exportModeloA'])
                ->name('monthly.reports.vat-modelo-a.export');

            Route::get('monthly-reports/irps-modelo-19', [MonthlyIrpsReportController::class, 'modelo19'])
                ->name('monthly.reports.irps-modelo-19');

            Route::get('monthly-reports/irps-modelo-22', [MonthlyIrpsReportController::class, 'modelo22'])
                ->name('monthly.reports.irps-modelo-22');

            Route::get('annual-reports/irpc-payment-guide', [IrpcReportController::class, 'index'])
                ->name('annual.reports.irpc-payment-guide');

            Route::get('reports-monthly-cashflow', [ReportController::class, 'monthlyCashflow'])->name('report.monthly.cashflow')->middleware(['auth', 'XSS']);
            Route::get('reports-quarterly-cashflow', [ReportController::class, 'quarterlyCashflow'])->name('report.quarterly.cashflow')->middleware(['auth', 'XSS']);
            Route::post('export/trial-balance', [ReportController::class, 'trialBalanceExport'])->name('trial.balance.export');
            Route::post('export/balance-sheet', [ReportController::class, 'balanceSheetExport'])->name('balance.sheet.export');
            Route::post('export/profit-loss', [ReportController::class, 'profitLossExport'])->name('profit.loss.export');
            Route::get('report/sales', [ReportController::class, 'salesReport'])->name('report.sales');
            Route::post('export/sales', [ReportController::class, 'salesReportExport'])->name('sales.export');
            Route::get('report/receivables', [ReportController::class, 'ReceivablesReport'])->name('report.receivables');
            Route::post('export/receivables', [ReportController::class, 'ReceivablesExport'])->name('receivables.export');
            Route::get('report/payables', [ReportController::class, 'PayablesReport'])->name('report.payables');

            // Product system (inventory) reports
            Route::get('reports/product-system', [ProductReportsController::class, 'index'])->name('reports.product.index');
            Route::get('reports/product-system/store-check', [ProductReportsController::class, 'storeCheck'])->name('reports.product.store-check');
            Route::post('reports/product-system/store-check', [ProductReportsController::class, 'storeStockAudit'])->name('reports.product.store-check.save');
            Route::get('reports/product-system/store-check/history', [ProductReportsController::class, 'storeCheckHistory'])->name('reports.product.store-check.history');
            Route::get('reports/product-system/store-check/{id}', [ProductReportsController::class, 'storeCheckShow'])->name('reports.product.store-check.show');
            Route::get('reports/product-system/warehouse-stock', [ProductReportsController::class, 'warehouseStock'])->name('reports.product.warehouse-stock');
            Route::get('reports/product-system/total-stock-value', [ProductReportsController::class, 'totalStockValue'])->name('reports.product.total-stock-value');
            Route::get('reports/product-system/total-stock-value/export', [ProductReportsController::class, 'totalStockValueExport'])->name('reports.product.total-stock-value.export');
            Route::get('reports/product-system/high-rotation', [ProductReportsController::class, 'highRotation'])->name('reports.product.high-rotation');
            Route::get('reports/product-system/high-rotation/export', [ProductReportsController::class, 'highRotationExport'])->name('reports.product.high-rotation.export');
            Route::get('reports/product-system/stock-movement', [ProductReportsController::class, 'stockMovement'])->name('reports.product.stock-movement');
            Route::get('reports/product-system/stock-movement/export', [ProductReportsController::class, 'stockMovementExport'])->name('reports.product.stock-movement.export');
            Route::get('reports/product-system/stock-movement/{kind}/{id}/detail', [ProductReportsController::class, 'stockMovementDetail'])->name('reports.product.stock-movement.detail');
            Route::get('reports/product-system/out-of-stock', [ProductReportsController::class, 'outOfStock'])->name('reports.product.out-of-stock');
            Route::get('reports/product-system/out-of-stock/export', [ProductReportsController::class, 'outOfStockExport'])->name('reports.product.out-of-stock.export');
            Route::get('reports/product-system/minimum-stock-alert', [ProductReportsController::class, 'minimumStockAlert'])->name('reports.product.minimum-stock-alert');
            Route::get('reports/product-system/minimum-stock-alert/export', [ProductReportsController::class, 'minimumStockAlertExport'])->name('reports.product.minimum-stock-alert.export');
            Route::get('reports/product-system/near-expiry', [ProductReportsController::class, 'nearExpiry'])->name('reports.product.near-expiry');
            Route::get('reports/product-system/near-expiry/export', [ProductReportsController::class, 'nearExpiryExport'])->name('reports.product.near-expiry.export');
            Route::get('reports/product-system/expired-products', [ProductReportsController::class, 'expiredProducts'])->name('reports.product.expired-products');
            Route::get('reports/product-system/expired-products/export', [ProductReportsController::class, 'expiredProductsExport'])->name('reports.product.expired-products.export');
            Route::post('reports/product-system/expired-products/convert-to-loss', [ProductReportsController::class, 'convertExpiredToLoss'])->name('reports.product.expired-products.convert-to-loss');
            Route::get('reports/product-system/transactions', [ProductReportsController::class, 'transactions'])->name('reports.product.transactions');
            Route::get('reports/product-system/transactions/export', [ProductReportsController::class, 'transactionsExport'])->name('reports.product.transactions.export');
            Route::get('reports/product-system/document/{docType}/{id}', [ProductReportsController::class, 'document'])->name('reports.product.document');
            Route::get('reports/product-system/last-supplies', [ProductReportsController::class, 'lastSupplies'])->name('reports.product.last-supplies');
            Route::get('reports/product-system/last-supplies/export', [ProductReportsController::class, 'lastSuppliesExport'])->name('reports.product.last-supplies.export');
            Route::get('reports/product-system/location-check', [ProductReportsController::class, 'locationCheck'])->name('reports.product.location-check');
            Route::get('reports/product-system/location-check/export', [ProductReportsController::class, 'locationCheckExport'])->name('reports.product.location-check.export');
        }
    );

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('proposal/{id}/status/change', [ProposalController::class, 'statusChange'])->name('proposal.status.change');
            Route::get('proposal/{id}/convert', [ProposalController::class, 'convert'])->name('proposal.convert');
            Route::get('proposal/{id}/duplicate', [ProposalController::class, 'duplicate'])->name('proposal.duplicate');
            Route::post('proposal/product/destroy', [ProposalController::class, 'productDestroy'])->name('proposal.product.destroy');
            Route::post('proposal/customer', [ProposalController::class, 'customer'])->name('proposal.customer');
            Route::post('proposal/product', [ProposalController::class, 'product'])->name('proposal.product');
            Route::get('proposal/items', [ProposalController::class, 'items'])->name('proposal.items');
            Route::get('proposal/{id}/sent', [ProposalController::class, 'sent'])->name('proposal.sent');
            Route::get('proposal/{id}/resent', [ProposalController::class, 'resent'])->name('proposal.resent');
            Route::resource('proposal', ProposalController::class);
        }
    );

    Route::get('/proposal/preview/{template}/{color}', [ProposalController::class, 'previewProposal'])->name('proposal.preview');
    Route::post('/proposal/template/setting', [ProposalController::class, 'saveProposalTemplateSettings'])->name('proposal.template.setting');

    Route::resource('goal', GoalController::class)->middleware(['auth', 'XSS', 'revalidate']);

    //Budget Planner //
    Route::resource('budget', BudgetController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::resource('account-assets', AssetController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::resource('custom-field', CustomFieldController::class)->middleware(['auth', 'XSS', 'revalidate']);

    Route::post('chart-of-account/subtype', [ChartOfAccountController::class, 'getSubType'])->name('charofAccount.subType')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('chart-of-account/parent-children', [ChartOfAccountController::class, 'getParentChildren'])->name('chart-of-account.parent-children')->middleware(['auth', 'XSS', 'revalidate']);

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::resource('chart-of-account', ChartOfAccountController::class);
        }
    );

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {

            Route::post('journal-entry/account/destroy', [JournalEntryController::class, 'accountDestroy'])->name('journal.account.destroy');

            Route::delete('journal-entry/journal/destroy/{item_id}', [JournalEntryController::class, 'journalDestroy'])->name('journal.destroy');
            Route::post('journal-entry/{journalEntry}/approve', [JournalEntryController::class, 'approve'])->name('journal-entry.approve');
            Route::post('journal-entry/{journalEntry}/reject', [JournalEntryController::class, 'reject'])->name('journal-entry.reject');
            Route::get('fiscal-year-closing', [FiscalYearClosingController::class, 'index'])->name('fiscal-year-closing.index');
            Route::post('fiscal-year-closing', [FiscalYearClosingController::class, 'runClosing'])->name('fiscal-year-closing.run');
            Route::post('fiscal-year-closing/opening', [FiscalYearClosingController::class, 'postOpeningBalances'])->name('fiscal-year-closing.opening');
            Route::post('fiscal-year-closing/opening/manual', [FiscalYearClosingController::class, 'postManualOpeningBalances'])->name('fiscal-year-closing.opening.manual');
            Route::post('fiscal-year-closing/vat', [FiscalYearClosingController::class, 'runVat'])->name('fiscal-year-closing.vat');
            Route::get('bank-reconciliation', [BankReconciliationController::class, 'index'])->name('bank-reconciliation.index');
            Route::get('bank-reconciliation/create', [BankReconciliationController::class, 'create'])->name('bank-reconciliation.create');
            Route::post('bank-reconciliation', [BankReconciliationController::class, 'store'])->name('bank-reconciliation.store');
            Route::get('bank-reconciliation/{bank_reconciliation_session}/map', [BankReconciliationController::class, 'map'])->name('bank-reconciliation.map');
            Route::post('bank-reconciliation/{bank_reconciliation_session}/map', [BankReconciliationController::class, 'applyMapping'])->name('bank-reconciliation.apply-mapping');
            Route::get('bank-reconciliation/{bank_reconciliation_session}', [BankReconciliationController::class, 'show'])->name('bank-reconciliation.show');
            Route::post('bank-reconciliation/{bank_reconciliation_session}/auto-match', [BankReconciliationController::class, 'autoMatch'])->name('bank-reconciliation.auto-match');
            Route::post('bank-reconciliation/{bank_reconciliation_session}/manual-match', [BankReconciliationController::class, 'manualMatch'])->name('bank-reconciliation.manual-match');
            Route::post('bank-reconciliation/{bank_reconciliation_session}/unmatch', [BankReconciliationController::class, 'unmatch'])->name('bank-reconciliation.unmatch');
            Route::resource('journal-entry', JournalEntryController::class);
        }
    );

    // Client Module

    Route::resource('clients', ClientController::class)->middleware(['auth', 'XSS']);

    Route::any('client-reset-password/{id}', [ClientController::class, 'clientPassword'])->name('clients.reset');
    Route::post('client-reset-password/{id}', [ClientController::class, 'clientPasswordReset'])->name('client.password.update');

    // Deal Module

    Route::post('/deals/user', [DealController::class, 'jsonUser'])->name('deal.user.json');
    Route::post('/deals/order', [DealController::class, 'order'])->name('deals.order')->middleware(['auth', 'XSS']);
    Route::post('/deals/change-pipeline', [DealController::class, 'changePipeline'])->name('deals.change.pipeline')->middleware(['auth', 'XSS']);
    Route::post('/deals/change-deal-status/{id}', [DealController::class, 'changeStatus'])->name('deals.change.status')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/labels', [DealController::class, 'labels'])->name('deals.labels')->middleware(['auth', 'XSS']);
    Route::post('/deals/{id}/labels', [DealController::class, 'labelStore'])->name('deals.labels.store')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/users', [DealController::class, 'userEdit'])->name('deals.users.edit')->middleware(['auth', 'XSS']);
    Route::put('/deals/{id}/users', [DealController::class, 'userUpdate'])->name('deals.users.update')->middleware(['auth', 'XSS']);
    Route::delete('/deals/{id}/users/{uid}', [DealController::class, 'userDestroy'])->name('deals.users.destroy')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/clients', [DealController::class, 'clientEdit'])->name('deals.clients.edit')->middleware(['auth', 'XSS']);
    Route::put('/deals/{id}/clients', [DealController::class, 'clientUpdate'])->name('deals.clients.update')->middleware(['auth', 'XSS']);
    Route::delete('/deals/{id}/clients/{uid}', [DealController::class, 'clientDestroy'])->name('deals.clients.destroy')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/products', [DealController::class, 'productEdit'])->name('deals.products.edit')->middleware(['auth', 'XSS']);
    Route::put('/deals/{id}/products', [DealController::class, 'productUpdate'])->name('deals.products.update')->middleware(['auth', 'XSS']);
    Route::delete('/deals/{id}/products/{uid}', [DealController::class, 'productDestroy'])->name('deals.products.destroy')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/sources', [DealController::class, 'sourceEdit'])->name('deals.sources.edit')->middleware(['auth', 'XSS']);
    Route::put('/deals/{id}/sources', [DealController::class, 'sourceUpdate'])->name('deals.sources.update')->middleware(['auth', 'XSS']);
    Route::delete('/deals/{id}/sources/{uid}', [DealController::class, 'sourceDestroy'])->name('deals.sources.destroy')->middleware(['auth', 'XSS']);
    Route::post('/deals/{id}/file', [DealController::class, 'fileUpload'])->name('deals.file.upload')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/file/{fid}', [DealController::class, 'fileDownload'])->name('deals.file.download')->middleware(['auth', 'XSS']);
    Route::delete('/deals/{id}/file/delete/{fid}', [DealController::class, 'fileDelete'])->name('deals.file.delete')->middleware(['auth', 'XSS']);
    Route::post('/deals/{id}/note', [DealController::class, 'noteStore'])->name('deals.note.store')->middleware(['auth']);
    Route::get('/deals/{id}/task', [DealController::class, 'taskCreate'])->name('deals.tasks.create')->middleware(['auth', 'XSS']);
    Route::post('/deals/{id}/task', [DealController::class, 'taskStore'])->name('deals.tasks.store')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/task/{tid}/show', [DealController::class, 'taskShow'])->name('deals.tasks.show')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/task/{tid}/edit', [DealController::class, 'taskEdit'])->name('deals.tasks.edit')->middleware(['auth', 'XSS']);
    Route::put('/deals/{id}/task/{tid}', [DealController::class, 'taskUpdate'])->name('deals.tasks.update')->middleware(['auth', 'XSS']);
    Route::put('/deals/{id}/task_status/{tid}', [DealController::class, 'taskUpdateStatus'])->name('deals.tasks.update_status')->middleware(['auth', 'XSS']);
    Route::delete('/deals/{id}/task/{tid}', [DealController::class, 'taskDestroy'])->name('deals.tasks.destroy')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/discussions', [DealController::class, 'discussionCreate'])->name('deals.discussions.create')->middleware(['auth', 'XSS']);
    Route::post('/deals/{id}/discussions', [DealController::class, 'discussionStore'])->name('deals.discussion.store')->middleware(['auth', 'XSS']);
    Route::get('/deals/{id}/permission/{cid}', [DealController::class, 'permission'])->name('deals.client.permission')->middleware(['auth', 'XSS']);
    Route::put('/deals/{id}/permission/{cid}', [DealController::class, 'permissionStore'])->name('deals.client.permissions.store')->middleware(['auth', 'XSS']);
    Route::get('/deals/list', [DealController::class, 'deal_list'])->name('deals.list')->middleware(['auth', 'XSS']);
    Route::get('/deals/export', [DealController::class, 'export'])->name('deals.export')->middleware(['auth', 'XSS']);
    Route::get('import/deals/file', [DealController::class, 'importFile'])->name('deals.import');
    Route::post('deals/import', [DealController::class, 'fileImport'])->name('deals.file.import');
    Route::get('import/deals/modal', [DealController::class, 'fileImportModal'])->name('deals.import.modal');
    Route::post('import/deals', [DealController::class, 'dealImportdata'])->name('deals.import.data');

    // Deal Calls

    Route::get('/deals/{id}/call', [DealController::class, 'callCreate'])->name('deals.calls.create')->middleware(['auth', 'XSS']);
    Route::post('/deals/{id}/call', [DealController::class, 'callStore'])->name('deals.calls.store')->middleware(['auth']);
    Route::get('/deals/{id}/call/{cid}/edit', [DealController::class, 'callEdit'])->name('deals.calls.edit')->middleware(['auth']);
    Route::put('/deals/{id}/call/{cid}', [DealController::class, 'callUpdate'])->name('deals.calls.update')->middleware(['auth']);
    Route::delete('/deals/{id}/call/{cid}', [DealController::class, 'callDestroy'])->name('deals.calls.destroy')->middleware(['auth', 'XSS']);

    // Deal Email

    Route::get('/deals/{id}/email', [DealController::class, 'emailCreate'])->name('deals.emails.create')->middleware(['auth', 'XSS']);
    Route::post('/deals/{id}/email', [DealController::class, 'emailStore'])->name('deals.emails.store')->middleware(['auth', 'XSS']);

    Route::resource('deals', DealController::class)->middleware(['auth', 'XSS']);

    // end Deal Module

    Route::get('/search', [UserController::class, 'search'])->name('search.json');
    Route::post('/stages/order', [StageController::class, 'order'])->name('stages.order');
    Route::post('/stages/json', [StageController::class, 'json'])->name('stages.json');

    Route::resource('stages', StageController::class);
    Route::resource('pipelines', PipelineController::class);
    Route::resource('labels', LabelController::class);
    Route::resource('sources', SourceController::class);
    Route::resource('payments', PaymentController::class);
    Route::resource('custom_fields', CustomFieldController::class);

    // Leads Module

    Route::post('/lead_stages/order', [LeadStageController::class, 'order'])->name('lead_stages.order');

    Route::resource('lead_stages', LeadStageController::class)->middleware(['auth']);

    Route::post('/leads/json', [LeadController::class, 'json'])->name('leads.json');
    Route::post('/leads/order', [LeadController::class, 'order'])->name('leads.order')->middleware(['auth', 'XSS']);
    Route::get('/leads/list', [LeadController::class, 'lead_list'])->name('leads.list')->middleware(['auth', 'XSS']);
    Route::post('/leads/{id}/file', [LeadController::class, 'fileUpload'])->name('leads.file.upload')->middleware(['auth', 'XSS']);
    Route::get('/leads/{id}/file/{fid}', [LeadController::class, 'fileDownload'])->name('leads.file.download')->middleware(['auth', 'XSS']);
    Route::delete('/leads/{id}/file/delete/{fid}', [LeadController::class, 'fileDelete'])->name('leads.file.delete')->middleware(['auth', 'XSS']);
    Route::post('/leads/{id}/note', [LeadController::class, 'noteStore'])->name('leads.note.store')->middleware(['auth']);
    Route::get('/leads/{id}/labels', [LeadController::class, 'labels'])->name('leads.labels')->middleware(['auth', 'XSS']);
    Route::post('/leads/{id}/labels', [LeadController::class, 'labelStore'])->name('leads.labels.store')->middleware(['auth', 'XSS']);
    Route::get('/leads/{id}/users', [LeadController::class, 'userEdit'])->name('leads.users.edit')->middleware(['auth', 'XSS']);
    Route::put('/leads/{id}/users', [LeadController::class, 'userUpdate'])->name('leads.users.update')->middleware(['auth', 'XSS']);
    Route::delete('/leads/{id}/users/{uid}', [LeadController::class, 'userDestroy'])->name('leads.users.destroy')->middleware(['auth', 'XSS']);
    Route::get('/leads/{id}/products', [LeadController::class, 'productEdit'])->name('leads.products.edit')->middleware(['auth', 'XSS']);
    Route::put('/leads/{id}/products', [LeadController::class, 'productUpdate'])->name('leads.products.update')->middleware(['auth', 'XSS']);
    Route::delete('/leads/{id}/products/{uid}', [LeadController::class, 'productDestroy'])->name('leads.products.destroy')->middleware(['auth', 'XSS']);
    Route::get('/leads/{id}/sources', [LeadController::class, 'sourceEdit'])->name('leads.sources.edit')->middleware(['auth', 'XSS']);
    Route::put('/leads/{id}/sources', [LeadController::class, 'sourceUpdate'])->name('leads.sources.update')->middleware(['auth', 'XSS']);
    Route::delete('/leads/{id}/sources/{uid}', [LeadController::class, 'sourceDestroy'])->name('leads.sources.destroy')->middleware(['auth', 'XSS']);
    Route::get('/leads/{id}/discussions', [LeadController::class, 'discussionCreate'])->name('leads.discussions.create')->middleware(['auth', 'XSS']);
    Route::post('/leads/{id}/discussions', [LeadController::class, 'discussionStore'])->name('leads.discussion.store')->middleware(['auth', 'XSS']);
    Route::get('/leads/{id}/show_convert', [LeadController::class, 'showConvertToDeal'])->name('leads.convert.deal')->middleware(['auth', 'XSS']);
    Route::post('/leads/{id}/convert', [LeadController::class, 'convertToDeal'])->name('leads.convert.to.deal')->middleware(['auth', 'XSS']);
    Route::get('/leads/export', [LeadController::class, 'export'])->name('leads.export')->middleware(['auth', 'XSS']);

    // Route::post('import/leads', [LeadController::class, 'import'])->name('leads.import');
    Route::get('import/leads/file', [LeadController::class, 'importFile'])->name('leads.import');
    Route::post('leads/import', [LeadController::class, 'fileImport'])->name('leads.file.import');
    Route::get('import/leads/modal', [LeadController::class, 'fileImportModal'])->name('leads.import.modal');
    Route::post('import/leads', [LeadController::class, 'leadImportdata'])->name('leads.import.data');


    // Lead Calls
    Route::get('/leads/{id}/call', [LeadController::class, 'callCreate'])->name('leads.calls.create')->middleware(['auth', 'XSS']);
    Route::post('/leads/{id}/call', [LeadController::class, 'callStore'])->name('leads.calls.store')->middleware(['auth']);
    Route::get('/leads/{id}/call/{cid}/edit', [LeadController::class, 'callEdit'])->name('leads.calls.edit')->middleware(['auth', 'XSS']);
    Route::put('/leads/{id}/call/{cid}', [LeadController::class, 'callUpdate'])->name('leads.calls.update')->middleware(['auth']);
    Route::delete('/leads/{id}/call/{cid}', [LeadController::class, 'callDestroy'])->name('leads.calls.destroy')->middleware(['auth', 'XSS']);

    // Lead Email

    Route::get('/leads/{id}/email', [LeadController::class, 'emailCreate'])->name('leads.emails.create')->middleware(['auth', 'XSS']);
    Route::post('/leads/{id}/email', [LeadController::class, 'emailStore'])->name('leads.emails.store')->middleware(['auth']);

    Route::resource('leads', LeadController::class)->middleware(['auth', 'XSS']);

    // end Leads Module

    Route::get('user/{id}/plan', [UserController::class, 'upgradePlan'])->name('plan.upgrade')->middleware(['auth', 'XSS']);
    Route::get('user/{id}/plan/{pid}', [UserController::class, 'activePlan'])->name('plan.active')->middleware(['auth', 'XSS']);
    Route::get('/{uid}/notification/seen', [UserController::class, 'notificationSeen'])->name('notification.seen');

    // Email Templates
    Route::get('email_template_lang/{id}/{lang?}', [EmailTemplateController::class, 'manageEmailLang'])->name('manage.email.language')->middleware(['auth', 'XSS']);
    Route::any('email_template_store', [EmailTemplateController::class, 'updateStatus'])->name('status.email.language')->middleware(['auth']);
    Route::any('email_template_store/{pid}', [EmailTemplateController::class, 'storeEmailLang'])->name('store.email.language')->middleware(['auth']);
    Route::resource('email_template', EmailTemplateController::class)->middleware(['auth', 'XSS']);
    Route::get('email-logs', [EmailLogController::class, 'index'])->name('email_logs.index')->middleware(['auth', 'XSS']);
    Route::get('dev-tool', [DevToolController::class, 'index'])->name('dev-tool.index')->middleware(['auth', 'XSS']);
    Route::post('dev-tool/clear-log', [DevToolController::class, 'clearLog'])->name('dev-tool.clear-log')->middleware(['auth', 'XSS']);
    Route::post('dev-tool/migrate', [DevToolController::class, 'migrate'])->name('dev-tool.migrate')->middleware(['auth', 'XSS']);
    Route::post('dev-tool/run-seeder', [DevToolController::class, 'runSeeder'])->name('dev-tool.run-seeder')->middleware(['auth', 'XSS']);
    Route::post('dev-tool/clear-all-caches', [DevToolController::class, 'clearAllCaches'])->name('dev-tool.clear-all-caches')->middleware(['auth', 'XSS']);
    Route::post('dev-tool/toggle-debug', [DevToolController::class, 'toggleDebug'])->name('dev-tool.toggle-debug')->middleware(['auth', 'XSS']);
    Route::get('document-void-logs', [DocumentVoidLogController::class, 'index'])->name('document_void_logs.index')->middleware(['auth', 'XSS']);
    // End Email Templates

    // HRM
    Route::resource('user', UserController::class)->middleware(['auth', 'XSS']);
    Route::post('employee/json', [EmployeeController::class, 'json'])->name('employee.json')->middleware(['auth', 'XSS']);
    Route::post('branch/employee/json', [EmployeeController::class, 'employeeJson'])->name('branch.employee.json')->middleware(['auth', 'XSS']);
    Route::get('employee-profile', [EmployeeController::class, 'profile'])->name('employee.profile')->middleware(['auth', 'XSS']);
    Route::get('show-employee-profile/{id}', [EmployeeController::class, 'profileShow'])->name('show.employee.profile')->middleware(['auth', 'XSS']);

    Route::get('lastlogin', [EmployeeController::class, 'lastLogin'])->name('lastlogin')->middleware(['auth', 'XSS']);

    Route::resource('employee', EmployeeController::class)->middleware(['auth', 'XSS']);

    Route::post('employee/getdepartment', [EmployeeController::class, 'getDepartment'])->name('employee.getdepartment')->middleware(['auth', 'XSS']);

    Route::resource('department', DepartmentController::class)->middleware(['auth', 'XSS']);
    Route::resource('designation', DesignationController::class)->middleware(['auth', 'XSS']);
    Route::resource('document', DocumentController::class)->middleware(['auth', 'XSS']);
    Route::resource('branch', BranchController::class)->middleware(['auth', 'XSS']);

    // Hrm EmployeeController

    Route::get('employee/salary/{eid}', [SetSalaryController::class, 'employeeBasicSalary'])->name('employee.basic.salary')->middleware(['auth', 'XSS']);

    //payslip

    Route::resource('paysliptype', PayslipTypeController::class)->middleware(['auth', 'XSS']);
    Route::resource('allowance', AllowanceController::class)->except(['index'])->middleware(['auth', 'XSS']);
    Route::resource('commission', CommissionController::class)->middleware(['auth', 'XSS']);
    Route::resource('allowanceoption', AllowanceOptionController::class)->middleware(['auth', 'XSS']);
    Route::resource('loanoption', LoanOptionController::class)->middleware(['auth', 'XSS']);
    Route::resource('deductionoption', DeductionOptionController::class)->middleware(['auth', 'XSS']);
    Route::resource('loan', LoanController::class)->middleware(['auth', 'XSS']);
    Route::resource('saturationdeduction', SaturationDeductionController::class)->middleware(['auth', 'XSS']);
    Route::resource('otherpayment', OtherPaymentController::class)->middleware(['auth', 'XSS']);
    Route::resource('overtime', OvertimeController::class)->middleware(['auth', 'XSS']);

    Route::get('employee/salary/{eid}', [SetSalaryController::class, 'employeeBasicSalary'])->name('employee.basic.salary')->middleware(['auth', 'XSS']);
    Route::post('employee/update/sallary/{id}', [SetSalaryController::class, 'employeeUpdateSalary'])->name('employee.salary.update')->middleware(['auth', 'XSS']);
    Route::get('salary/employeeSalary', [SetSalaryController::class, 'employeeSalary'])->name('employeesalary')->middleware(['auth', 'XSS']);
    Route::resource('setsalary', SetSalaryController::class)->middleware(['auth', 'XSS']);

    Route::get('allowances/create/{eid}', [AllowanceController::class, 'allowanceCreate'])->name('allowances.create')->middleware(['auth', 'XSS']);
    Route::get('commissions/create/{eid}', [CommissionController::class, 'commissionCreate'])->name('commissions.create')->middleware(['auth', 'XSS']);
    Route::get('loans/create/{eid}', [LoanController::class, 'loanCreate'])->name('loans.create')->middleware(['auth', 'XSS']);
    Route::get('saturationdeductions/create/{eid}', [SaturationDeductionController::class, 'saturationdeductionCreate'])->name('saturationdeductions.create')->middleware(['auth', 'XSS']);
    Route::get('otherpayments/create/{eid}', [OtherPaymentController::class, 'otherpaymentCreate'])->name('otherpayments.create')->middleware(['auth', 'XSS']);
    Route::get('overtimes/create/{eid}', [OvertimeController::class, 'overtimeCreate'])->name('overtimes.create')->middleware(['auth', 'XSS']);
    Route::get('payslip/paysalary/{id}/{date}', [PaySlipController::class, 'paysalary'])->name('payslip.paysalary')->middleware(['auth', 'XSS']);
    Route::get('payslip/bulk_pay_create/{date}', [PaySlipController::class, 'bulk_pay_create'])->name('payslip.bulk_pay_create')->middleware(['auth', 'XSS']);
    Route::post('payslip/bulkpayment/{date}', [PaySlipController::class, 'bulkpayment'])->name('payslip.bulkpayment')->middleware(['auth', 'XSS']);
    Route::post('payslip/search_json', [PaySlipController::class, 'search_json'])->name('payslip.search_json')->middleware(['auth', 'XSS']);
    Route::get('payslip/employeepayslip', [PaySlipController::class, 'employeepayslip'])->name('payslip.employeepayslip')->middleware(['auth', 'XSS']);
    Route::get('payslip/showemployee/{id}', [PaySlipController::class, 'showemployee'])->name('payslip.showemployee')->middleware(['auth', 'XSS']);
    Route::get('payslip/editemployee/{id}', [PaySlipController::class, 'editemployee'])->name('payslip.editemployee')->middleware(['auth', 'XSS']);
    Route::post('payslip/editemployee/{id}', [PaySlipController::class, 'updateEmployee'])->name('payslip.updateemployee')->middleware(['auth', 'XSS']);
    Route::get('payslip/pdf/{id}/{m}', [PaySlipController::class, 'pdf'])->name('payslip.pdf')->middleware(['auth', 'XSS']);

    Route::get('payslip/send/{id}/{m}', [PaySlipController::class, 'send'])->name('payslip.send')->middleware(['auth', 'XSS']);
    Route::get('payslip/delete/{id}', [PaySlipController::class, 'destroy'])->name('payslip.delete')->middleware(['auth', 'XSS']);
    Route::get('payslip/logo', [PaySlipController::class, 'logoForm'])->name('payslip.logo')->middleware(['auth', 'XSS']);
    Route::post('payslip/logo', [PaySlipController::class, 'logoStore'])->name('payslip.logo.store')->middleware(['auth', 'XSS']);
    Route::resource('payslip', PaySlipController::class)->middleware(['auth', 'XSS'])->except(['create', 'show']);

    Route::resource('company-policy', CompanyPolicyController::class)->middleware(['auth', 'XSS']);
    Route::resource('indicator', IndicatorController::class)->middleware(['auth', 'XSS']);
    Route::resource('appraisal', AppraisalController::class)->middleware(['auth', 'XSS']);

    Route::post('branch/employee/json', [EmployeeController::class, 'employeeJson'])->name('branch.employee.json')->middleware(['auth', 'XSS']);

    Route::resource('goaltype', GoalTypeController::class)->middleware(['auth', 'XSS']);
    Route::resource('goaltracking', GoalTrackingController::class)->middleware(['auth', 'XSS']);
    Route::resource('account-assets', AssetController::class)->middleware(['auth', 'XSS']);

    Route::post('event/getdepartment', [EventController::class, 'getdepartment'])->name('event.getdepartment')->middleware(['auth', 'XSS']);
    Route::post('event/getemployee', [EventController::class, 'getemployee'])->name('event.getemployee')->middleware(['auth', 'XSS']);

    Route::resource('event', EventController::class)->middleware(['auth', 'XSS']);

    Route::post('meeting/getdepartment', [MeetingController::class, 'getdepartment'])->name('meeting.getdepartment')->middleware(['auth', 'XSS']);
    Route::post('meeting/getemployee', [MeetingController::class, 'getemployee'])->name('meeting.getemployee')->middleware(['auth', 'XSS']);

    Route::resource('meeting', MeetingController::class)->middleware(['auth', 'XSS']);
    Route::resource('trainingtype', TrainingTypeController::class)->middleware(['auth', 'XSS']);
    Route::resource('trainer', TrainerController::class)->middleware(['auth', 'XSS']);

    Route::post('training/status', [TrainingController::class, 'updateStatus'])->name('training.status')->middleware(['auth', 'XSS']);

    Route::resource('training', TrainingController::class)->middleware(['auth', 'XSS']);

    // HRM - HR Module

    Route::resource('awardtype', AwardTypeController::class)->middleware(['auth', 'XSS']);
    Route::resource('award', AwardController::class)->middleware(['auth', 'XSS']);
    Route::resource('resignation', ResignationController::class)->middleware(['auth', 'XSS']);
    Route::resource('travel', TravelController::class)->middleware(['auth', 'XSS']);
    Route::resource('promotion', PromotionController::class)->middleware(['auth', 'XSS']);
    Route::resource('complaint', ComplaintController::class)->middleware(['auth', 'XSS']);
    Route::resource('warning', WarningController::class)->middleware(['auth', 'XSS']);

    Route::resource('termination', TerminationController::class)->middleware(['auth', 'XSS']);
    Route::get('termination/{id}/description', [TerminationController::class, 'description'])->name('termination.description');
    Route::resource('terminationtype', TerminationTypeController::class)->middleware(['auth', 'XSS']);

    Route::post('announcement/getdepartment', [AnnouncementController::class, 'getdepartment'])->name('announcement.getdepartment');
    Route::post('announcement/getemployee', [AnnouncementController::class, 'getemployee'])->name('announcement.getemployee');
    Route::resource('announcement', AnnouncementController::class)->middleware(['auth', 'XSS']);

    Route::resource('holiday', HolidayController::class)->middleware(['auth', 'XSS']);
    Route::get('holiday-calender', [HolidayController::class, 'calender'])->name('holiday.calender');

    // Recruitement

    Route::resource('job-category', JobCategoryController::class)->middleware(['auth', 'XSS']);

    Route::resource('job-stage', JobStageController::class)->middleware(['auth', 'XSS']);
    Route::post('job-stage/order', [JobStageController::class, 'order'])->name('job.stage.order');

    Route::resource('job', JobController::class)->middleware(['auth', 'XSS']);

    Route::get('candidates-job-applications', [JobApplicationController::class, 'candidate'])->name('job.application.candidate')->middleware(['auth', 'XSS']);

    Route::resource('job-application', JobApplicationController::class)->middleware(['auth', 'XSS']);
    Route::post('job-application/order', [JobApplicationController::class, 'order'])->name('job.application.order')->middleware(['XSS']);
    Route::post('job-application/{id}/rating', [JobApplicationController::class, 'rating'])->name('job.application.rating')->middleware(['XSS']);
    Route::delete('job-application/{id}/archive', [JobApplicationController::class, 'archive'])->name('job.application.archive')->middleware(['auth', 'XSS']);
    Route::post('job-application/{id}/skill/store', [JobApplicationController::class, 'addSkill'])->name('job.application.skill.store')->middleware(['auth', 'XSS']);
    Route::post('job-application/{id}/note/store', [JobApplicationController::class, 'addNote'])->name('job.application.note.store')->middleware(['auth', 'XSS']);
    Route::delete('job-application/{id}/note/destroy', [JobApplicationController::class, 'destroyNote'])->name('job.application.note.destroy')->middleware(['auth', 'XSS']);
    Route::post('job-application/getByJob', [JobApplicationController::class, 'getByJob'])->name('get.job.application')->middleware(['auth', 'XSS']);
    Route::get('job-onboard', [JobApplicationController::class, 'jobOnBoard'])->name('job.on.board')->middleware(['auth', 'XSS']);
    Route::get('job-onboard/create/{id}', [JobApplicationController::class, 'jobBoardCreate'])->name('job.on.board.create')->middleware(['auth', 'XSS']);
    Route::post('job-onboard/store/{id}', [JobApplicationController::class, 'jobBoardStore'])->name('job.on.board.store')->middleware(['auth', 'XSS']);
    Route::get('job-onboard/edit/{id}', [JobApplicationController::class, 'jobBoardEdit'])->name('job.on.board.edit')->middleware(['auth', 'XSS']);
    Route::post('job-onboard/update/{id}', [JobApplicationController::class, 'jobBoardUpdate'])->name('job.on.board.update')->middleware(['auth', 'XSS']);
    Route::delete('job-onboard/delete/{id}', [JobApplicationController::class, 'jobBoardDelete'])->name('job.on.board.delete')->middleware(['auth', 'XSS']);
    Route::get('job-onboard/convert/{id}', [JobApplicationController::class, 'jobBoardConvert'])->name('job.on.board.converts')->middleware(['auth', 'XSS']);
    Route::post('job-onboard/convert/{id}', [JobApplicationController::class, 'jobBoardConvertData'])->name('job.on.board.convert')->middleware(['auth', 'XSS']);
    Route::post('job-application/stage/change', [JobApplicationController::class, 'stageChange'])->name('job.application.stage.change')->middleware(['auth', 'XSS']);

    Route::resource('custom-question', CustomQuestionController::class)->middleware(['auth', 'XSS']);
    Route::resource('interview-schedule', InterviewScheduleController::class)->middleware(['auth', 'XSS']);
    Route::get('taskboard/{view?}', [ProjectTaskController::class, 'taskBoard'])->name('taskBoard.view')->middleware(['auth', 'XSS']);
    Route::get('taskboard-view', [ProjectTaskController::class, 'taskboardView'])->name('project.taskboard.view')->middleware(['auth', 'XSS']);

    Route::resource('document-upload', DucumentUploadController::class)->middleware(['auth', 'XSS']);
    Route::resource('transfer', TransferController::class)->middleware(['auth', 'XSS']);
    Route::get('attendanceemployee/bulkattendance', [AttendanceEmployeeController::class, 'bulkAttendance'])->name('attendanceemployee.bulkattendance')->middleware(['auth', 'XSS']);
    Route::post('attendanceemployee/bulkattendances', [AttendanceEmployeeController::class, 'bulkAttendanceData'])->name('attendanceemployee.bulkattendances')->middleware(['auth', 'XSS']);
    Route::post('attendanceemployee/attendance', [AttendanceEmployeeController::class, 'attendance'])->name('attendanceemployee.attendance')->middleware(['auth', 'XSS']);

    Route::resource('attendanceemployee', AttendanceEmployeeController::class)->middleware(['auth', 'XSS']);
    Route::resource('leavetype', LeaveTypeController::class)->middleware(['auth', 'XSS']);
    Route::get('report-leave', [ReportController::class, 'leave'])->name('report.leave')->middleware(['auth', 'XSS']);
    Route::get('employee/{id}/leave/{status}/{type}/{month}/{year}', [ReportController::class, 'employeeLeave'])->name('report.employee.leave')->middleware(['auth', 'XSS']);
    Route::get('leave/{id}/action', [LeaveController::class, 'action'])->name('leave.action')->middleware(['auth', 'XSS']);
    Route::post('leave/changeaction', [LeaveController::class, 'changeaction'])->name('leave.changeaction')->middleware(['auth', 'XSS']);
    Route::post('leave/jsoncount', [LeaveController::class, 'jsoncount'])->name('leave.jsoncount')->middleware(['auth', 'XSS']);

    Route::resource('leave', LeaveController::class)->middleware(['auth', 'XSS']);


    Route::get('employee/{id}/leave/{status}/{type}/{month}/{year}', [ReportController::class, 'employeeLeave'])->name('report.employee.leave')->middleware(['auth', 'XSS']);

    Route::get('reports-payroll', [ReportController::class, 'payroll'])->name('report.payroll')->middleware(['auth', 'XSS']);
    Route::post('reports-payroll/getdepartment', [ReportController::class, 'getPayrollDepartment'])->name('report.payroll.getdepartment')->middleware(['auth', 'XSS']);
    Route::post('reports-payroll/getemployee', [ReportController::class, 'getPayrollEmployee'])->name('report.payroll.getemployee')->middleware(['auth', 'XSS']);

    Route::get('reports-hrm-tax', [HrmTaxReportController::class, 'index'])->name('report.hrm.tax')->middleware(['auth', 'XSS']);

    Route::get('reports-monthly-attendance', [ReportController::class, 'monthlyAttendance'])->name('report.monthly.attendance')->middleware(['auth', 'XSS']);
    Route::get('report/attendance/{month}/{branch}/{department}', [ReportController::class, 'exportCsv'])->name('report.attendance')->middleware(['auth', 'XSS']);

    //crm report
    Route::get('reports-lead', [ReportController::class, 'leadReport'])->name('report.lead')->middleware(['auth', 'XSS']);
    Route::get('reports-deal', [ReportController::class, 'dealReport'])->name('report.deal')->middleware(['auth', 'XSS']);

    //pos report
    Route::get('reports-warehouse', [ReportController::class, 'warehouseReport'])->name('report.warehouse')->middleware(['auth', 'XSS']);

    Route::get('reports-daily-purchase', [ReportController::class, 'purchaseDailyReport'])->name('report.daily.purchase')->middleware(['auth', 'XSS']);
    Route::get('reports-monthly-purchase', [ReportController::class, 'purchaseMonthlyReport'])->name('report.monthly.purchase')->middleware(['auth', 'XSS']);

    Route::get('reports-daily-pos', [ReportController::class, 'posDailyReport'])->name('report.daily.pos')->middleware(['auth', 'XSS']);
    Route::get('reports-monthly-pos', [ReportController::class, 'posMonthlyReport'])->name('report.monthly.pos')->middleware(['auth', 'XSS']);
    Route::get('report/pos-eod', [ReportController::class, 'posEodReport'])->name('report.pos.eod')->middleware(['auth', 'XSS']);
    Route::get('report/pos-eod/export', [ReportController::class, 'posEodReportExport'])->name('report.pos.eod.export')->middleware(['auth', 'XSS']);
    Route::get('report/pos-eod/download', [ReportController::class, 'posEodReportDownload'])->name('report.pos.eod.download')->middleware(['auth', 'XSS']);
    Route::post('report/pos-eod/send-email', [ReportController::class, 'posEodReportSendEmail'])->name('report.pos.eod.send')->middleware(['auth', 'XSS']);
    Route::post('report/pos-close-day', [ReportController::class, 'posCloseDay'])->name('report.pos.close.day')->middleware(['auth', 'XSS']);
    Route::post('report/pos-reopen-day', [ReportController::class, 'posReopenDay'])->name('report.pos.reopen.day')->middleware(['auth', 'XSS']);
    Route::get('report/pos-day-summary', [ReportController::class, 'posDaySummary'])->name('report.pos.day-summary')->middleware(['auth', 'XSS']);
    Route::get('report/pos-low-stock', [ReportController::class, 'posLowStockReport'])->name('report.pos.low-stock')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant', [ReportController::class, 'restaurantReportsIndex'])->name('report.restaurant.index')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant/sales-by-category', [ReportController::class, 'posSalesByCategoriesReport'])->name('report.restaurant.sales-by-category')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant/sales-by-category/export', [ReportController::class, 'posSalesByCategoriesExport'])->name('report.restaurant.sales-by-category.export')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant/sales-by-product', [ReportController::class, 'posSalesByProductReport'])->name('report.restaurant.sales-by-product')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant/sales-by-waiter', [ReportController::class, 'posSalesByWaiterReport'])->name('report.restaurant.sales-by-waiter')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant/cancellations', [ReportController::class, 'posCancellationsReport'])->name('report.restaurant.cancellations')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant/discounts', [ReportController::class, 'posDiscountsReport'])->name('report.restaurant.discounts')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant/best-selling', [ReportController::class, 'posBestSellingReport'])->name('report.restaurant.best-selling')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant/gross-profit', [ReportController::class, 'posGrossProfitReport'])->name('report.restaurant.gross-profit')->middleware(['auth', 'XSS']);
    Route::get('report/restaurant/cash-discrepancies', [ReportController::class, 'posCashDiscrepanciesReport'])->name('report.restaurant.cash-discrepancies')->middleware(['auth', 'XSS']);
    Route::redirect('product-recipe', '/productservice')->name('product-recipe.index')->middleware(['auth', 'XSS']);

    Route::get('reports-pos-vs-purchase', [ReportController::class, 'posVsPurchaseReport'])->name('report.pos.vs.purchase')->middleware(['auth', 'XSS']);
    Route::get('reports-pos-by-branch', [ReportController::class, 'posBranchReport'])->name('report.pos.branch')->middleware(['auth', 'XSS']);
    Route::get('report/pos-shift', [ReportController::class, 'posShiftReport'])->name('report.pos.shift')->middleware(['auth', 'XSS']);
    Route::get('report/pos-shift/export', [ReportController::class, 'posShiftReportExport'])->name('report.pos.shift.export')->middleware(['auth', 'XSS']);

    // User Module

    Route::get('users/{view?}', [UserController::class, 'index'])->name('users')->middleware(['auth', 'XSS']);
    Route::get('users-view', [UserController::class, 'filterUserView'])->name('filter.user.view')->middleware(['auth', 'XSS']);
    Route::get('checkuserexists', [UserController::class, 'checkUserExists'])->name('user.exists')->middleware(['auth', 'XSS']);
    Route::get('profile', [UserController::class, 'profile'])->name('profile')->middleware(['auth', 'XSS']);
    Route::post('/profile', [UserController::class, 'updateProfile'])->name('update.profile')->middleware(['auth', 'XSS']);
    Route::get('user/info/{id}', [UserController::class, 'userInfo'])->name('users.info')->middleware(['auth', 'XSS']);
    Route::get('user/{id}/info/{type}', [UserController::class, 'getProjectTask'])->name('user.info.popup')->middleware(['auth', 'XSS']);
    // End User Module

    // Search
    Route::get('/search', [UserController::class, 'search'])->name('search.json');
    // end

    // Milestone Module

    Route::get('projects/{id}/milestone', [ProjectController::class, 'milestone'])->name('project.milestone')->middleware(['auth', 'XSS']);

    //Route::delete(
    //    '/projects/{id}/users/{uid}', [
    //                                    'as' => 'projects.users.destroy',
    //                                    'uses' => 'ProjectController@userDestroy',
    //                                ]
    //)->middleware(
    //    [
    //        'auth',
    //        'XSS',
    //    ]
    //);
    Route::post('projects/{id}/milestone', [ProjectController::class, 'milestoneStore'])->name('project.milestone.store')->middleware(['auth', 'XSS']);
    Route::get('projects/milestone/{id}/edit', [ProjectController::class, 'milestoneEdit'])->name('project.milestone.edit')->middleware(['auth', 'XSS']);
    Route::post('projects/milestone/{id}', [ProjectController::class, 'milestoneUpdate'])->name('project.milestone.update')->middleware(['auth', 'XSS']);
    Route::delete('projects/milestone/{id}', [ProjectController::class, 'milestoneDestroy'])->name('project.milestone.destroy')->middleware(['auth', 'XSS']);
    Route::get('projects/milestone/{id}/show', [ProjectController::class, 'milestoneShow'])->name('project.milestone.show')->middleware(['auth', 'XSS']);

    // End Milestone

    // Project Module

    Route::get('invite-project-member/{id}', [ProjectController::class, 'inviteMemberView'])->name('invite.project.member.view')->middleware(['auth', 'XSS']);
    Route::post('invite-project-user-member', [ProjectController::class, 'inviteProjectUserMember'])->name('invite.project.user.member')->middleware(['auth', 'XSS']);

    Route::delete('projects/{id}/users/{uid}', [ProjectController::class, 'destroyProjectUser'])->name('projects.user.destroy')->middleware(['auth', 'XSS']);
    Route::get('project/{view?}', [ProjectController::class, 'index'])->name('projects.list')->middleware(['auth', 'XSS']);
    Route::get('projects-view', [ProjectController::class, 'filterProjectView'])->name('filter.project.view')->middleware(['auth', 'XSS']);
    Route::post('projects/{id}/store-stages/{slug}', [ProjectController::class, 'storeProjectTaskStages'])->name('project.stages.store')->middleware(['auth', 'XSS']);

    Route::patch('remove-user-from-project/{project_id}/{user_id}', [ProjectController::class, 'removeUserFromProject'])->name('remove.user.from.project')->middleware(['auth', 'XSS']);
    Route::get('projects-users', [ProjectController::class, 'loadUser'])->name('project.user')->middleware(['auth', 'XSS']);
    Route::get('projects/{id}/gantt/{duration?}', [ProjectController::class, 'gantt'])->name('projects.gantt')->middleware(['auth', 'XSS']);
    Route::post('projects/{id}/gantt', [ProjectController::class, 'ganttPost'])->name('projects.gantt.post')->middleware(['auth', 'XSS']);

    Route::resource('projects', ProjectController::class)->middleware(['auth', 'XSS']);

    // User Permission
    Route::get('projects/{id}/user/{uid}/permission', [ProjectController::class, 'userPermission'])->name('projects.user.permission')->middleware(['auth', 'XSS']);
    Route::post('projects/{id}/user/{uid}/permission', [ProjectController::class, 'userPermissionStore'])->name('projects.user.permission.store')->middleware(['auth', 'XSS']);

    // End Project Module

    // Task Module

    Route::get('stage/{id}/tasks', [ProjectTaskController::class, 'getStageTasks'])->name('stage.tasks')->middleware(['auth', 'XSS']);

    // Project Task Module

    Route::get('/projects/{id}/task', [ProjectTaskController::class, 'index'])->name('projects.tasks.index')->middleware(['auth', 'XSS']);
    Route::get('/projects/{pid}/task/{sid}', [ProjectTaskController::class, 'create'])->name('projects.tasks.create')->middleware(['auth', 'XSS']);
    Route::post('/projects/{pid}/task/{sid}', [ProjectTaskController::class, 'store'])->name('projects.tasks.store')->middleware(['auth', 'XSS']);
    Route::get('/projects/{id}/task/{tid}/show', [ProjectTaskController::class, 'show'])->name('projects.tasks.show')->middleware(['auth', 'XSS']);
    Route::get('/projects/{id}/task/{tid}/edit', [ProjectTaskController::class, 'edit'])->name('projects.tasks.edit')->middleware(['auth', 'XSS']);
    Route::post('/projects/{id}/task/update/{tid}', [ProjectTaskController::class, 'update'])->name('projects.tasks.update')->middleware(['auth', 'XSS']);
    Route::delete('/projects/{id}/task/{tid}', [ProjectTaskController::class, 'destroy'])->name('projects.tasks.destroy')->middleware(['auth', 'XSS']);
    Route::patch('/projects/{id}/task/order', [ProjectTaskController::class, 'taskOrderUpdate'])->name('tasks.update.order')->middleware(['auth', 'XSS']);
    Route::patch('update-task-priority-color', [ProjectTaskController::class, 'updateTaskPriorityColor'])->name('update.task.priority.color')->middleware(['auth', 'XSS']);

    Route::post('/projects/{id}/comment/{tid}/file', [ProjectTaskController::class, 'commentStoreFile'])->name('comment.store.file')->middleware(['auth', 'XSS']);
    Route::delete('/projects/{id}/comment/{tid}/file/{fid}', [ProjectTaskController::class, 'commentDestroyFile'])->name('comment.destroy.file');
    Route::post('/projects/{id}/comment/{tid}', [ProjectTaskController::class, 'commentStore'])->name('task.comment.store');
    Route::delete('/projects/{id}/comment/{tid}/{cid}', [ProjectTaskController::class, 'commentDestroy'])->name('comment.destroy');
    Route::post('/projects/{id}/checklist/{tid}', [ProjectTaskController::class, 'checklistStore'])->name('checklist.store');
    Route::post('/projects/{id}/checklist/update/{cid}', [ProjectTaskController::class, 'checklistUpdate'])->name('checklist.update');
    Route::delete('/projects/{id}/checklist/{cid}', [ProjectTaskController::class, 'checklistDestroy'])->name('checklist.destroy');
    Route::post('/projects/{id}/change/{tid}/fav', [ProjectTaskController::class, 'changeFav'])->name('change.fav');
    Route::post('/projects/{id}/change/{tid}/complete', [ProjectTaskController::class, 'changeCom'])->name('change.complete');
    Route::post('/projects/{id}/change/{tid}/progress', [ProjectTaskController::class, 'changeProg'])->name('change.progress');
    Route::get('/projects/task/{id}/get', [ProjectTaskController::class, 'taskGet'])->name('projects.tasks.get')->middleware(['auth', 'XSS']);
    Route::get('/calendar/{id}/show', [ProjectTaskController::class, 'calendarShow'])->name('task.calendar.show')->middleware(['auth', 'XSS']);
    Route::post('/calendar/{id}/drag', [ProjectTaskController::class, 'calendarDrag'])->name('task.calendar.drag');
    Route::get('calendar/{task}/{pid?}', [ProjectTaskController::class, 'calendarView'])->name('task.calendar')->middleware(['auth', 'XSS']);

    Route::resource('project-task-stages', TaskStageController::class)->middleware(['auth', 'XSS']);
    Route::post('/project-task-stages/order', [TaskStageController::class, 'order'])->name('project-task-stages.order');

    Route::post('project-task-new-stage', [TaskStageController::class, 'storingValue'])->name('new-task-stage')->middleware(['auth', 'XSS']);
    // End Task Module

    // Project Expense Module
    Route::get('/projects/{id}/expense', [ProjectExpenseController::class, 'index'])->name('projects.expenses.index')->middleware(['auth', 'XSS']);
    Route::get('/projects/{pid}/expense/create', [ProjectExpenseController::class, 'create'])->name('projects.expenses.create')->middleware(['auth', 'XSS']);
    Route::post('/projects/{pid}/expense/store', [ProjectExpenseController::class, 'store'])->name('projects.expenses.store')->middleware(['auth', 'XSS']);
    Route::get('/projects/{id}/expense/{eid}/edit', [ProjectExpenseController::class, 'edit'])->name('projects.expenses.edit')->middleware(['auth', 'XSS']);
    Route::post('/projects/{id}/expense/{eid}', [ProjectExpenseController::class, 'update'])->name('projects.expenses.update')->middleware(['auth', 'XSS']);
    Route::delete('/projects/{eid}/expense/', [ProjectExpenseController::class, 'destroy'])->name('projects.expenses.destroy')->middleware(['auth', 'XSS']);
    Route::get('/expense-list', [ExpenseController::class, 'expenseList'])->name('expense.list')->middleware(['auth', 'XSS']);

    // contract type
    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::resource('contractType', ContractTypeController::class);
        }
    );

    // Project Timesheet
    Route::get('append-timesheet-task-html', [TimesheetController::class, 'appendTimesheetTaskHTML'])->name('append.timesheet.task.html')->middleware(['auth', 'XSS']);
    //    Route::get('timesheet-table-view', [TimesheetController::class, 'filterTimesheetTableView'])->name('filter.timesheet.table.view')->middleware(['auth', 'XSS']);
    Route::get('timesheet-view', [TimesheetController::class, 'filterTimesheetView'])->name('filter.timesheet.view')->middleware(['auth', 'XSS']);
    Route::get('timesheet-list', [TimesheetController::class, 'timesheetList'])->name('timesheet.list')->middleware(['auth', 'XSS']);
    Route::get('timesheet-list-get', [TimesheetController::class, 'timesheetListGet'])->name('timesheet.list.get')->middleware(['auth', 'XSS']);
    Route::get('/project/{id}/timesheet', [TimesheetController::class, 'timesheetView'])->name('timesheet.index')->middleware(['auth', 'XSS']);
    Route::get('/project/{id}/timesheet/create', [TimesheetController::class, 'timesheetCreate'])->name('timesheet.create')->middleware(['auth', 'XSS']);
    Route::post('/project/timesheet', [TimesheetController::class, 'timesheetStore'])->name('timesheet.store')->middleware(['auth', 'XSS']);
    Route::get('/project/timesheet/{project_id}/edit/{timesheet_id}', [TimesheetController::class, 'timesheetEdit'])->name('timesheet.edit')->middleware(['auth', 'XSS']);
    Route::any('/project/timesheet/update/{timesheet_id}', [TimesheetController::class, 'timesheetUpdate'])->name('timesheet.update')->middleware(['auth', 'XSS']);

    Route::delete('/project/timesheet/{timesheet_id}', [TimesheetController::class, 'timesheetDestroy'])->name('timesheet.destroy')->middleware(['auth', 'XSS']);

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
            ],
        ],
        function () {
            Route::resource('projectstages', ProjectstagesController::class);
            Route::post('/projectstages/order', [ProjectstagesController::class, 'order'])->name('projectstages.order')->middleware(['auth', 'XSS']);
            Route::post('projects/bug/kanban/order', [ProjectController::class, 'bugKanbanOrder'])->name('bug.kanban.order');
            Route::get('projects/{id}/bug/kanban', [ProjectController::class, 'bugKanban'])->name('task.bug.kanban');
            Route::get('projects/{id}/bug', [ProjectController::class, 'bug'])->name('task.bug');
            Route::get('projects/{id}/bug/create', [ProjectController::class, 'bugCreate'])->name('task.bug.create');
            Route::post('projects/{id}/bug/store', [ProjectController::class, 'bugStore'])->name('task.bug.store');
            Route::get('projects/{id}/bug/{bid}/edit', [ProjectController::class, 'bugEdit'])->name('task.bug.edit');
            Route::post('projects/{id}/bug/{bid}/update', [ProjectController::class, 'bugUpdate'])->name('task.bug.update');
            Route::delete('projects/{id}/bug/{bid}/destroy', [ProjectController::class, 'bugDestroy'])->name('task.bug.destroy');
            Route::get('projects/{id}/bug/{bid}/show', [ProjectController::class, 'bugShow'])->name('task.bug.show');
            Route::post('projects/{id}/bug/{bid}/comment', [ProjectController::class, 'bugCommentStore'])->name('bug.comment.store');
            Route::post('projects/bug/{bid}/file', [ProjectController::class, 'bugCommentStoreFile'])->name('bug.comment.file.store');
            Route::delete('projects/bug/comment/{id}', [ProjectController::class, 'bugCommentDestroy'])->name('bug.comment.destroy');
            Route::delete('projects/bug/file/{id}', [ProjectController::class, 'bugCommentDestroyFile'])->name('bug.comment.file.destroy');

            Route::resource('bugstatus', BugStatusController::class);
            Route::post('/bugstatus/order', [BugStatusController::class, 'order'])->name('bugstatus.order');
            Route::get('bugs-report/{view?}', [ProjectTaskController::class, 'allBugList'])->name('bugs.view')->middleware(['auth', 'XSS']);
        }
    );

    // User_Todo Module
    Route::post('/todo/create', [UserController::class, 'todo_store'])->name('todo.store')->middleware(['auth', 'XSS']);
    Route::post('/todo/{id}/update', [UserController::class, 'todo_update'])->name('todo.update')->middleware(['auth', 'XSS']);
    Route::delete('/todo/{id}', [UserController::class, 'todo_destroy'])->name('todo.destroy')->middleware(['auth', 'XSS']);
    Route::get('/change/mode', [UserController::class, 'changeMode'])->name('change.mode')->middleware(['auth', 'XSS']);
    Route::get('dashboard-view', [DashboardController::class, 'filterView'])->name('dashboard.view')->middleware(['auth', 'XSS']);
    Route::get('dashboard', [DashboardController::class, 'clientView'])->name('client.dashboard.view')->middleware(['auth', 'XSS']);

    // saas
    Route::resource('users', UserController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('plans', PlanController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('plan-trial/{id}', [PlanController::class, 'planTrial'])->name('plan.trial')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('plan-disable', [PlanController::class, 'planDisable'])->name('plan.disable')->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('coupons', CouponController::class)->middleware(['auth', 'XSS', 'revalidate']);

    // Orders

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('/orders', [StripePaymentController::class, 'index'])->name('order.index');
            Route::get('/refund/{id}/{user_id}', [StripePaymentController::class, 'refund'])->name('order.refund');
            Route::get('/stripe/{code}', [StripePaymentController::class, 'stripe'])->name('stripe');
            Route::post('/stripe', [StripePaymentController::class, 'stripePost'])->name('stripe.post');
        }
    );

    Route::get('/apply-coupon', [CouponController::class, 'applyCoupon'])->name('apply.coupon')->middleware(['auth', 'XSS', 'revalidate']);

    //================================= Form Builder ====================================//

    // Form Builder
    Route::resource('form_builder', FormBuilderController::class)->middleware(['auth', 'XSS']);

    // Form link base view


    // Form Field
    Route::get('/form_builder/{id}/field', [FormBuilderController::class, 'fieldCreate'])->name('form.field.create')->middleware(['auth', 'XSS']);
    Route::post('/form_builder/{id}/field', [FormBuilderController::class, 'fieldStore'])->name('form.field.store')->middleware(['auth', 'XSS']);
    Route::get('/form_builder/{id}/field/{fid}/show', [FormBuilderController::class, 'fieldShow'])->name('form.field.show')->middleware(['auth', 'XSS']);
    Route::get('/form_builder/{id}/field/{fid}/edit', [FormBuilderController::class, 'fieldEdit'])->name('form.field.edit')->middleware(['auth', 'XSS']);
    Route::post('/form_builder/{id}/field/{fid}', [FormBuilderController::class, 'fieldUpdate'])->name('form.field.update')->middleware(['auth', 'XSS']);
    Route::delete('/form_builder/{id}/field/{fid}', [FormBuilderController::class, 'fieldDestroy'])->name('form.field.destroy')->middleware(['auth', 'XSS']);

    // Form Response
    Route::get('/form_response/{id}', [FormBuilderController::class, 'viewResponse'])->name('form.response')->middleware(['auth', 'XSS']);
    Route::get('/response/{id}', [FormBuilderController::class, 'responseDetail'])->name('response.detail')->middleware(['auth', 'XSS']);

    // Form Field Bind
    Route::get('/form_field/{id}', [FormBuilderController::class, 'formFieldBind'])->name('form.field.bind')->middleware(['auth', 'XSS']);
    Route::post('/form_field_store/{id}}', [FormBuilderController::class, 'bindStore'])->name('form.bind.store')->middleware(['auth', 'XSS']);

    // contract

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('contract/{id}/description', [ContractController::class, 'description'])->name('contract.description');
            Route::get('contract/grid', [ContractController::class, 'grid'])->name('contract.grid');
            Route::resource('contract', ContractController::class);
        }
    );
    Route::post('/contract/{id}/file', [ContractController::class, 'fileUpload'])->name('contract.file.upload')->middleware(['auth', 'XSS']);
    Route::get('contract/pdf/{id}', [ContractController::class, 'pdffromcontract'])->name('contract.download.pdf')->middleware(['auth']);
    Route::get('contract/{id}/get_contract', [ContractController::class, 'printContract'])->name('get.contract')->middleware(['auth']);
    Route::post('/contract_status_edit/{id}', [ContractController::class, 'contract_status_edit'])->name('contract.status')->middleware(['auth', 'XSS']);
    Route::post('contract/{id}/contract_description', [ContractController::class, 'contract_descriptionStore'])->name('contract.contract_description.store')->middleware(['auth']);
    Route::get('/contract/{id}/file/{fid}', [ContractController::class, 'fileDownload'])->name('contracts.file.download')->middleware(['auth', 'XSS']);
    Route::delete('/contract/{id}/file/delete/{fid}', [ContractController::class, 'fileDelete'])->name('contracts.file.delete')->middleware(['auth', 'XSS']);
    Route::get('/contract/copy/{id}', [ContractController::class, 'copycontract'])->name('contract.copy')->middleware(['auth', 'XSS']);
    Route::post('/contract/copy/store', [ContractController::class, 'copycontractstore'])->name('contract.copy.store')->middleware(['auth', 'XSS']);
    Route::get('/contract/{id}/mail', [ContractController::class, 'sendmailContract'])->name('send.mail.contract');
    Route::get('/signature/{id}', [ContractController::class, 'signature'])->name('signature')->middleware(['auth']);
    Route::post('/signaturestore', [ContractController::class, 'signatureStore'])->name('signaturestore')->middleware(['auth', 'XSS']);
    Route::post('/contract/{id}/comment', [ContractController::class, 'commentStore'])->name('comment.store');
    Route::post('/contract/{id}/notes', [ContractController::class, 'noteStore'])->name('note_store.store')->middleware(['auth']);
    Route::delete('/contract/{id}/notes', [ContractController::class, 'noteDestroy'])->name('note_store.destroy')->middleware(['auth']);
    Route::delete('/contract/{id}/comment', [ContractController::class, 'commentDestroy'])->name('comment_store.destroy');
    Route::get('get-projects/{client_id}', [ContractController::class, 'clientByProject'])->name('project.by.user.id')->middleware(['auth', 'XSS']);

    // client wise project show in modal

    Route::any('/contract/clients/select/{bid}', [ContractController::class, 'clientwiseproject'])->name('contract.clients.select');

    // copy contract

    Route::get('/contract/copy/{id}', [ContractController::class, 'copycontract'])->name('contract.copy')->middleware(['auth', 'XSS']);
    Route::post('contract/copy/store', [ContractController::class, 'copycontractstore'])->name('contract.copy.store')->middleware(['auth', 'XSS']);

    // Custom Landing Page

    //    Route::get('/landingpage', [LandingPageSectionController::class, 'index'])->name('custom_landing_page.index')->middleware(['auth', 'XSS']);
    //    Route::get('/LandingPage/show/{id}', [LandingPageSectionController::class, 'show']);
    //
    //    Route::post('/LandingPage/setConetent', [LandingPageSectionController::class, 'setConetent'])->middleware(['auth', 'XSS']);
    //
    //
    //    Route::get(
    //        '/get_landing_page_section/{name}', function ($name) {
    //        $plans = \DB::table('plans')->get();
    //
    //        return view('custom_landing_page.' . $name, compact('plans'));
    //    }
    //    );
    //
    //    Route::post('/LandingPage/removeSection/{id}', [LandingPageSectionController::class, 'removeSection'])->middleware(['auth', 'XSS']);
    //    Route::post('/LandingPage/setOrder', [LandingPageSectionController::class, 'setOrder'])->middleware(['auth', 'XSS']);
    //    Route::post('/LandingPage/copySection', [LandingPageSectionController::class, 'copySection'])->middleware(['auth', 'XSS']);

    // Plan Payment Gateways
    Route::post('plan-pay-with-bank', [BankTransferPaymentController::class, 'planPayWithBank'])->name('plan.pay.with.bank')->middleware(['auth', 'XSS', 'revalidate']);

    Route::post('plan-pay-with-paypal', [PaypalController::class, 'planPayWithPaypal'])->name('plan.pay.with.paypal')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('{id}/plan-get-payment-status', [PaypalController::class, 'planGetPaymentStatus'])->name('plan.get.payment.status')->middleware(['auth', 'XSS', 'revalidate']);

    Route::post('/plan-pay-with-paystack', [PaystackPaymentController::class, 'planPayWithPaystack'])->name('plan.pay.with.paystack')->middleware(['auth', 'XSS']);
    Route::get('/plan/paystack/{pay_id}/{plan_id}', [PaystackPaymentController::class, 'getPaymentStatus'])->name('plan.paystack');

    Route::post('/plan-pay-with-flaterwave', [FlutterwavePaymentController::class, 'planPayWithFlutterwave'])->name('plan.pay.with.flaterwave')->middleware(['auth', 'XSS']);
    Route::get('/plan/flaterwave/{txref}/{plan_id}', [FlutterwavePaymentController::class, 'getPaymentStatus'])->name('plan.flaterwave');

    Route::post('/plan-pay-with-razorpay', [RazorpayPaymentController::class, 'planPayWithRazorpay'])->name('plan.pay.with.razorpay')->middleware(['auth', 'XSS']);
    Route::get('/plan/razorpay/{txref}/{plan_id}', [RazorpayPaymentController::class, 'getPaymentStatus'])->name('plan.razorpay');

    Route::post('/plan-pay-with-paytm', [PaytmPaymentController::class, 'planPayWithPaytm'])->name('plan.pay.with.paytm')->middleware(['auth', 'XSS']);
    Route::post('/plan/paytm/{plan}', [PaytmPaymentController::class, 'getPaymentStatus'])->name('plan.paytm');

    Route::post('/plan-pay-with-mercado', [MercadoPaymentController::class, 'planPayWithMercado'])->name('plan.pay.with.mercado')->middleware(['auth', 'XSS']);
    Route::get('/plan/mercado/{plan}/{amount}', [MercadoPaymentController::class, 'getPaymentStatus'])->name('plan.mercado');

    Route::post('/plan-pay-with-mollie', [MolliePaymentController::class, 'planPayWithMollie'])->name('plan.pay.with.mollie')->middleware(['auth', 'XSS']);
    Route::get('/plan/mollie/{plan}', [MolliePaymentController::class, 'getPaymentStatus'])->name('plan.mollie');

    Route::post('/plan-pay-with-skrill', [SkrillPaymentController::class, 'planPayWithSkrill'])->name('plan.pay.with.skrill')->middleware(['auth', 'XSS']);
    Route::get('/plan/skrill/{plan}', [SkrillPaymentController::class, 'getPaymentStatus'])->name('plan.skrill');

    Route::post('/plan-pay-with-coingate', [CoingatePaymentController::class, 'planPayWithCoingate'])->name('plan.pay.with.coingate')->middleware(['auth', 'XSS']);
    Route::get('/plan/coingate/{plan}', [CoingatePaymentController::class, 'getPaymentStatus'])->name('plan.coingate');

    Route::post('/toyyibpay', [ToyyibpayController::class, 'planPayWithToyyibpay'])->name('plan.toyyibpaypayment');
    Route::get('/plan-pay-with-toyyibpay/{id}/{status}/{coupon}', [ToyyibpayController::class, 'getPaymentStatus'])->name('plan.status');

    Route::post('payfast-plan', [PayFastController::class, 'planPayWithPayfast'])->name('payfast.payment');
    Route::get('payfast-plan/{success}', [PayFastController::class, 'getPaymentStatus'])->name('payfast.payment.success');

    Route::post('iyzipay/prepare', [IyziPayController::class, 'initiatePayment'])->name('iyzipay.payment.init');
    Route::post('iyzipay/callback/plan/{id}/{amount}/{coupan_code?}', [IyzipayController::class, 'iyzipayCallback'])->name('iyzipay.payment.callback');

    Route::post('/sspay', [SspayController::class, 'SspayPaymentPrepare'])->name('plan.sspaypayment');
    Route::get('sspay-payment-plan/{plan_id}/{amount}/{couponCode}', [SspayController::class, 'SspayPlanGetPayment'])->middleware(['auth'])->name('plan.sspay.callback');

    Route::post('plan-pay-with-paytab', [PaytabController::class, 'planPayWithpaytab'])->middleware(['auth'])->name('plan.pay.with.paytab');
    Route::any('paytab-success/plan', [PaytabController::class, 'PaytabGetPayment'])->middleware(['auth'])->name('plan.paytab.success');

    Route::any('/payment/initiate', [BenefitPaymentController::class, 'initiatePayment'])->name('plan.pay.with.benefit');
    Route::any('call_back', [BenefitPaymentController::class, 'call_back'])->name('benefit.call_back');

    Route::post('cashfree/payments/store', [CashfreeController::class, 'cashfreePaymentStore'])->name('plan.pay.with.cashfree');
    Route::any('cashfree/payments/success', [CashfreeController::class, 'cashfreePaymentSuccess'])->name('cashfreePayment.success');

    Route::post('/aamarpay/payment', [AamarpayController::class, 'pay'])->name('plan.pay.with.aamarpay')->middleware('auth');
    Route::any('/aamarpay/payment/{data}', [AamarpayController::class, 'aamarpaysuccess'])->name('pay.aamarpay.success')->middleware('auth');

    Route::post('/paytr/payment/{plan_id}', [PaytrController::class, 'PlanpayWithPaytr'])->name('plan.pay.with.paytr');
    Route::get('/paytr/sussess/', [PaytrController::class, 'paytrsuccess'])->name('pay.paytr.success');

    Route::post('/plan/yookassa/payment', [YooKassaController::class, 'planPayWithYooKassa'])->name('plan.pay.with.yookassa');
    Route::get('/plan/yookassa/{plan}', [YooKassaController::class, 'planGetYooKassaStatus'])->name('plan.yookassa.status');

    Route::any('/midtrans', [MidtransPaymentController::class, 'planPayWithMidtrans'])->name('plan.pay.with.midtrans');
    Route::any('/midtrans/callback', [MidtransPaymentController::class, 'planGetMidtransStatus'])->name('plan.get.midtrans.status');

    Route::any('/xendit/payment', [XenditPaymentController::class, 'planPayWithXendit'])->name('plan.pay.with.xendit');
    Route::any('/xendit/payment/status', [XenditPaymentController::class, 'planGetXenditStatus'])->name('plan.xendit.status');

    Route::post('/nepalste/payment', [NepalstePaymnetController::class, 'planPayWithnepalste'])->name('plan.pay.with.nepalste');
    Route::get('nepalste/status/', [NepalstePaymnetController::class, 'planGetNepalsteStatus'])->name('nepalste.status');
    Route::get('nepalste/cancel/', [NepalstePaymnetController::class, 'planGetNepalsteCancel'])->name('nepalste.cancel');

    Route::post('/paiementpro/payment', [PaiementProController::class, 'planPayWithPaiementpro'])->name('plan.pay.with.paiementpro');
    Route::any('/paiementpro/status', [PaiementProController::class, 'planGetPaiementProStatus'])->name('plan.get.paiementpro.status');

    Route::post('/plan/company/payment', [CinetPayController::class, 'planPayWithCinetPay'])->name('plan.pay.with.cinetpay');
    Route::post('/plan/company/payment/return', [CinetPayController::class,'planCinetPayReturn'])->name('plan.cinetpay.return');
    Route::post('/plan/company/payment/notify/', [CinetPayController::class, 'planCinetPayNotify'])->name('plan.cinetpay.notify');

    Route::post('/fedapay', [FedapayController::class, 'planPayWithFedapay'])->name('plan.pay.with.fedapay');
    Route::get('/fedapay/status', [FedapayController::class, 'planGetFedapayStatus'])->name('plan.get.fedapay.status');

    Route::post('/payhere', [PayHereController::class, 'planPayWithPayHere'])->name('plan.pay.with.payhere');
    Route::get('/payhere/status', [PayHereController::class, 'planGetPayHereStatus'])->name('plan.get.payhere.status');

    Route::post('plan-pay-with/tap', [TapController::class, 'planPayWithTap'])->name('plan.pay.with.tap');
    Route::get('plan-get-tap-status/{plan_id}',[TapController::class,'planGetTapStatus'])->name('plan.get.tap.status');

    Route::any('/plan-pay-with-authorize-net', [AuthorizeNetController::class, 'planPayWithAuthorizeNet'])->name('plan.pay.with.authorizenet');
    Route::post('/plan-get-authorizenet-status',[AuthorizeNetController::class,'planPayWithAuthorizeNetData'])->name('plan.get.authorizenet.status');

    Route::post('plan-pay-with-khalti', [KhaltiController::class, 'planPayWithKhalti'])->name('plan.pay.with.khalti');
    Route::post('plan-get-khalti-status',[KhaltiController::class,'planGetKhaltiStatus'])->name('plan.get.khalti.status');

    Route::post('/plan-pay-with-easebuzz', [EasebuzzController::class,'planPayWithEasebuzz'])->name('plan.pay.with.easebuzz');
    Route::match(['get','post'],'/plan-easebuzz-payment-return', [EasebuzzController::class,'return_url'])->name('plan.easebuzz.return');
    Route::match(['get','post'],'plan-easebuzz-payment-notify', [EasebuzzController::class,'notify_url'])->name('plan.get.easebuzz.notify');

    Route::post('plan-pay-with/ozow', [OzowPaymentController::class, 'planPayWithOzow'])->name('plan.pay.with.ozow');
    Route::get('plan-get-ozow-status/{plan_id}',[OzowPaymentController::class,'planGetOzowStatus'])->name('plan.get.ozow.status');

    // ---------------------********************************-----------------------
    //plan-order
    Route::post('order/{id}/changeaction', [BankTransferPaymentController::class, 'changeStatus'])->name('order.changestatus');
    Route::delete('order/{id}', [BankTransferPaymentController::class, 'orderDestroy'])->name('order.destroy');
    Route::get('order/{id}/action', [BankTransferPaymentController::class, 'action'])->name('order.action');


    //    Route::post('plan-pay-with-paypal', [PaypalController::class, 'planPayWithPaypal'])->name('plan.pay.with.paypal')->middleware(['auth', 'XSS', 'revalidate']);
    //    Route::get('{id}/plan-get-payment-status', [PaypalController::class, 'planGetPaymentStatus'])->name('plan.get.payment.status')->middleware(['auth', 'XSS', 'revalidate']);

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('support/{id}/reply', [SupportController::class, 'reply'])->name('support.reply');
            Route::post('support/{id}/reply', [SupportController::class, 'replyAnswer'])->name('support.reply.answer');
            Route::get('support/grid', [SupportController::class, 'grid'])->name('support.grid');
            Route::resource('support', SupportController::class);
        }
    );

    Route::resource('competencies', CompetenciesController::class)->middleware(['auth', 'XSS']);

    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::resource('performanceType', PerformanceTypeController::class);
        }
    );

    // Plan Request Module
    Route::get('plan_request', [PlanRequestController::class, 'index'])->name('plan_request.index')->middleware(['auth', 'XSS']);
    Route::get('request_frequency/{id}', [PlanRequestController::class, 'requestView'])->name('request.view')->middleware(['auth', 'XSS']);
    Route::get('request_send/{id}', [PlanRequestController::class, 'userRequest'])->name('send.request')->middleware(['auth', 'XSS']);
    Route::get('request_response/{id}/{response}', [PlanRequestController::class, 'acceptRequest'])->name('response.request')->middleware(['auth', 'XSS']);
    Route::get('request_cancel/{id}', [PlanRequestController::class, 'cancelRequest'])->name('request.cancel')->middleware(['auth', 'XSS']);
    //QR Code Module

    // Import/Export Data Route

    Route::get('export/productservice', [ProductServiceController::class, 'export'])->name('productservice.export');
    Route::get('import/productservice/file', [ProductServiceController::class, 'importFile'])->name('productservice.file.import');
    // Route::post('import/productservice', [ProductServiceController::class, 'import'])->name('productservice.import');
    Route::post('import/productservice', [ProductServiceController::class, 'productserviceImportdata'])->name('productservice.import.data');

    Route::get('export/customer', [CustomerController::class, 'export'])->name('customer.export');
    Route::get('import/customer/file', [CustomerController::class, 'importFile'])->name('customer.file.import');
    Route::post('import/customer', [CustomerController::class, 'customerImportdata'])->name('customer.import.data');
    // Route::post('import/customer', [CustomerController::class, 'import'])->name('customer.import');
    Route::get('export/vender', [VenderController::class, 'export'])->name('vender.export');
    Route::get('import/vender/file', [VenderController::class, 'importFile'])->name('vender.file.import');
    Route::post('import/vender', [VenderController::class, 'venderImportdata'])->name('vender.import.data');
// Route::post('import/vender', [VenderController::class, 'import'])->name('vender.import');

    Route::get('export/invoice', [InvoiceController::class, 'export'])->name('invoice.export');
    Route::get('export/proposal', [ProposalController::class, 'export'])->name('proposal.export');
    Route::get('export/bill', [BillController::class, 'export'])->name('bill.export');
    
    Route::get('export/invoice-notes', [InvoiceController::class, 'exportInvoiceNotes'])->name('invoice.export.notes');
    Route::get('export/bills-notes', [BillController::class, 'exportBillNotes'])->name('bill.export.notes');
    

    Route::get('export/employee', [EmployeeController::class, 'export'])->name('employee.export');
    Route::get('import/employee/file', [EmployeeController::class, 'importFile'])->name('employee.file.import');
    Route::post('employee/import', [EmployeeController::class, 'fileImport'])->name('employee.import');
    Route::get('import/employee/modal', [EmployeeController::class, 'fileImportModal'])->name('employee.import.modal');
    Route::post('import/employee', [EmployeeController::class, 'employeeImportdata'])->name('employee.import.data');

    Route::get('import/attendance/file', [AttendanceEmployeeController::class, 'importFile'])->name('attendance.file.import');
    Route::post('csv/import', [ImportController::class, 'fileImport'])->name('csv.import');
    Route::get('import/csv/modal/', [ImportController::class, 'fileImportModal'])->name('csv.import.modal');
    Route::post('import/attendance', [AttendanceEmployeeController::class, 'attendanceImportdata'])->name('attendance.import.data');

    Route::get('export/transaction', [TransactionController::class, 'export'])->name('transaction.export');
    Route::get('export/accountstatement', [ReportController::class, 'export'])->name('accountstatement.export');
    Route::get('export/productstock', [ReportController::class, 'stock_export'])->name('productstock.export');
    Route::get('export/payroll', [ReportController::class, 'PayrollReportExport'])->name('payroll.export');
    Route::get('export/leave', [ReportController::class, 'LeaveReportExport'])->name('leave.export');

    Route::post('export/payslip', [PaySlipController::class, 'export'])->name('payslip.export');

    // Time-Tracker
    Route::post('stop-tracker', [DashboardController::class, 'stopTracker'])->name('stop.tracker')->middleware(['auth', 'XSS']);
    Route::get('time-tracker', [TimeTrackerController::class, 'index'])->name('time.tracker')->middleware(['auth', 'XSS']);
    Route::delete('tracker/{tid}/destroy', [TimeTrackerController::class, 'Destroy'])->name('tracker.destroy');
    Route::post('tracker/image-view', [TimeTrackerController::class, 'getTrackerImages'])->name('tracker.image.view');
    Route::delete('tracker/image-remove', [TimeTrackerController::class, 'removeTrackerImages'])->name('tracker.image.remove');
    Route::get('projects/time-tracker/{id}', [ProjectController::class, 'tracker'])->name('projecttime.tracker')->middleware(['auth', 'XSS']);

    // Zoom Meeting
    Route::resource('zoom-meeting', ZoomMeetingController::class)->middleware(['auth', 'XSS']);
    Route::any('/zoom-meeting/projects/select/{bid}', [ZoomMeetingController::class, 'projectwiseuser'])->name('zoom-meeting.projects.select');
    Route::get('zoom-meeting-calender', [ZoomMeetingController::class, 'calender'])->name('zoom-meeting.calender')->middleware(['auth', 'XSS']);

    // PaymentWall

    Route::post('/paymentwalls', [PaymentWallPaymentController::class, 'paymentwall'])->name('plan.paymentwallpayment')->middleware(['XSS']);
    Route::post('/plan-pay-with-paymentwall/{plan}', [PaymentWallPaymentController::class, 'planPayWithPaymentWall'])->name('plan.pay.with.paymentwall')->middleware(['auth', 'XSS']);
    Route::get('/plan/{flag}', [PaymentWallPaymentController::class, 'planeerror'])->name('error.plan.show');

    //POS System

    Route::get('quotation/items', [QuotationController::class, 'items'])->name('quotation.items');
    Route::resource('quotation', QuotationController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::any('quotation/create/{cid}', [QuotationController::class, 'quotationCreate'])->name('quotations.create')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('quotation/product', [QuotationController::class, 'product'])->name('quotation.product');
    Route::post('quotation/product/destroy', [QuotationController::class, 'productDestroy'])->name('quotation.product.destroy');
    Route::get('quotation/convert/{id}', [QuotationController::class, 'convert'])->name('quotation.convert');
    Route::get('quotation/{id}/convert-invoice', [QuotationController::class, 'convertToInvoiceForm'])->name('quotation.convert-invoice.form')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('quotation/{id}/convert-invoice', [QuotationController::class, 'convertToInvoiceStore'])->name('quotation.convert-invoice.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('quotation/{id}/convert-purchase', [QuotationController::class, 'convertToPurchaseForm'])->name('quotation.convert-purchase.form')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('quotation/{id}/convert-purchase', [QuotationController::class, 'convertToPurchaseStore'])->name('quotation.convert-purchase.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('quantity/product', [QuotationController::class, 'productQuantity'])->name('product.quantity');
    Route::post('/quotation/template/setting', [QuotationController::class, 'saveQuotationTemplateSettings'])->name('quotation.template.setting');
    Route::get('quotation/preview/{template}/{color}', [QuotationController::class, 'previewQuotation'])->name('quotation.preview')->middleware(['auth', 'XSS']);
    Route::get('printview/quotation', [QuotationController::class, 'printView'])->name('quotation.printview');
    Route::get('quotation/pdf/{id}', [QuotationController::class, 'quotation'])->name('quotation.pdf')->middleware(['auth', 'XSS', 'revalidate']);

    Route::get('proforma/from-quotation/{id}', [ProformaController::class, 'createFromQuotation'])->name('proforma.create-from-quotation')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('proforma/from-quotation/{id}', [ProformaController::class, 'storeFromQuotation'])->name('proforma.store-from-quotation')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('proforma/{id}/convert-purchase', [ProformaController::class, 'convertToPurchaseForm'])->name('proforma.convert-purchase.form')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('proforma/{id}/convert-purchase', [ProformaController::class, 'convertToPurchaseStore'])->name('proforma.convert-purchase.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('proforma/{id}/convert-invoice', [ProformaController::class, 'convertToInvoiceForm'])->name('proforma.convert-invoice.form')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('proforma/{id}/convert-invoice', [ProformaController::class, 'convertToInvoiceStore'])->name('proforma.convert-invoice.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('proforma/{id}/issue-invoice', [ProformaController::class, 'convertToInvoiceForm'])->name('proforma.issue-invoice.form')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('proforma/{id}/issue-invoice', [ProformaController::class, 'convertToInvoiceStore'])->name('proforma.issue-invoice.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('proforma/{id}/convert-bill', [ProformaController::class, 'convertToBillForm'])->name('proforma.convert-bill.form')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('proforma/{id}/convert-bill', [ProformaController::class, 'convertToBillStore'])->name('proforma.convert-bill.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('proforma/items', [ProformaController::class, 'items'])->name('proforma.items')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('proforma/product', [ProformaController::class, 'product'])->name('proforma.product')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('proforma/product/destroy', [ProformaController::class, 'productDestroy'])->name('proforma.product.destroy')->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('proforma', ProformaController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::any('proforma/create/{cid}', [ProformaController::class, 'proformaCreate'])->name('proformas.create')->middleware(['auth', 'XSS', 'revalidate']);

    Route::get('vendor-quote/items', [PurchaseQuotationController::class, 'items'])->name('vendor-quote.items')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('vendor-quote/product', [PurchaseQuotationController::class, 'product'])->name('vendor-quote.product')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('vendor-quote/product/destroy', [PurchaseQuotationController::class, 'productDestroy'])->name('vendor-quote.product.destroy')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('vendor-quote/convert-purchase/{id}', [PurchaseQuotationController::class, 'convertToPurchaseForm'])->name('vendor-quote.convert-purchase')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('vendor-quote/convert-purchase/{id}', [PurchaseQuotationController::class, 'convertToPurchase'])->name('vendor-quote.convert-purchase.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('vendor-quote/{id}/convert-proforma', [PurchaseQuotationController::class, 'convertToProformaForm'])->name('vendor-quote.convert-proforma.form')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('vendor-quote/{id}/convert-proforma', [PurchaseQuotationController::class, 'convertToProformaStore'])->name('vendor-quote.convert-proforma.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('vendor-quote/{id}/convert-purchase-proforma', [PurchaseProformaController::class, 'createFromVendorQuoteForm'])->name('vendor-quote.convert-purchase-proforma')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('vendor-quote/{id}/convert-purchase-proforma', [PurchaseProformaController::class, 'createFromVendorQuote'])->name('vendor-quote.convert-purchase-proforma.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('vendor-quote', PurchaseQuotationController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::any('vendor-quote/create/{cid}', [PurchaseQuotationController::class, 'quoteCreate'])->name('vendor_quotes.create')->middleware(['auth', 'XSS', 'revalidate']);

    Route::get('purchase-proforma/items', [PurchaseProformaController::class, 'items'])->name('purchase-proforma.items')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('purchase-proforma/product', [PurchaseProformaController::class, 'product'])->name('purchase-proforma.product')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('purchase-proforma/product/destroy', [PurchaseProformaController::class, 'productDestroy'])->name('purchase-proforma.product.destroy')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('purchase-proforma/convert-purchase/{id}', [PurchaseProformaController::class, 'convertToPurchaseForm'])->name('purchase-proforma.convert-purchase')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('purchase-proforma/convert-purchase/{id}', [PurchaseProformaController::class, 'convertToPurchase'])->name('purchase-proforma.convert-purchase.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('purchase-proforma', PurchaseProformaController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::any('purchase-proforma/create/{cid}', [PurchaseProformaController::class, 'proformaCreate'])->name('purchase_proformas.create')->middleware(['auth', 'XSS', 'revalidate']);

    Route::resource('warehouse', WarehouseController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('warehouse/{warehouse}/document', [WarehouseController::class, 'uploadDocument'])->name('warehouse.document.upload')->middleware(['auth', 'XSS', 'revalidate']);
    Route::delete('warehouse/{warehouse}/document/{documentId}', [WarehouseController::class, 'deleteDocument'])->name('warehouse.document.delete')->middleware(['auth', 'XSS', 'revalidate']);
    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::get('purchase/items', [PurchaseController::class, 'items'])->name('purchase.items');
            Route::resource('purchase', PurchaseController::class);

            //    Route::get('/bill/{id}/', 'PurchaseController@purchaseLink')->name('purchase.link.copy');
            Route::get('purchase/{id}/payments', [PurchaseController::class, 'payment'])->name('purchase.payments');
            Route::post('purchase/{id}/payment', [PurchaseController::class, 'createPayment'])->name('purchase.payment');
            Route::post('purchase/{id}/payment/{pid}/destroy', [PurchaseController::class, 'paymentDestroy'])->name('purchase.payment.destroy');
            Route::post('purchase/product/destroy', [PurchaseController::class, 'productDestroy'])->name('purchase.product.destroy');
            Route::post('purchase/vender', [PurchaseController::class, 'vender'])->name('purchase.vender');
            Route::post('purchase/product', [PurchaseController::class, 'product'])->name('purchase.product');
            Route::get('purchase/{id}/sent', [PurchaseController::class, 'sent'])->name('purchase.sent');
            Route::get('purchase/{id}/resent', [PurchaseController::class, 'resent'])->name('purchase.resent');
            Route::get('purchase/convert-vendor-bill/{id}', [PurchaseController::class, 'convertToVendorBill'])->name('purchase.convert-vendor-bill');
            Route::get('purchase/convert-invoice/{id}', [PurchaseController::class, 'convertToInvoiceForm'])->name('purchase.convert-invoice.form');
            Route::post('purchase/convert-invoice/{id}', [PurchaseController::class, 'convertToInvoiceStore'])->name('purchase.convert-invoice.store');
        }

    );
    Route::get('pos-print-setting', [SystemController::class, 'posPrintIndex'])->name('pos.print.setting')->middleware(['auth', 'XSS']);
    Route::get('purchase/preview/{template}/{color}', [PurchaseController::class, 'previewPurchase'])->name('purchase.preview')->middleware(['auth', 'XSS']);
    Route::get('pos/preview/{template}/{color}', [PosController::class, 'previewPos'])->name('pos.preview')->middleware(['auth', 'XSS']);
    Route::get('pos/agent-template-preview/{template}', [PosController::class, 'previewAgentInvoiceTemplate'])->name('pos.agent.template.preview')->middleware(['auth', 'XSS']);
    Route::get('pos-sales', [PosController::class, 'create'])->name('pos.create')->middleware(['auth', 'XSS']);

    Route::post('/purchase/template/setting', [PurchaseController::class, 'savePurchaseTemplateSettings'])->name('purchase.template.setting');
    Route::post('/pos/template/setting', [PosController::class, 'savePosTemplateSettings'])->name('pos.template.setting');

    Route::get('purchase/pdf/{id}', [PurchaseController::class, 'purchase'])->name('purchase.pdf')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('pos/pdf/{id}', [PosController::class, 'pos'])->name('pos.pdf')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('pos/data/store', [PosController::class, 'store'])->name('pos.data.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('pos/sync', [PosController::class, 'sync'])->name('pos.sync')->middleware(['auth', 'XSS', 'revalidate']);
    Route::match(['get', 'post'], 'pos/sync-status', [PosController::class, 'syncStatus'])->name('pos.sync.status')->middleware(['auth', 'XSS']);

    Route::get('pos/balances', [PosController::class, 'balances'])->name('pos.balances')->middleware(['auth', 'XSS']);
    
    Route::get('pos/add-opening-balance', [PosController::class, 'add_opening_balance'])->name('pos.add_opening_balance')->middleware(['auth', 'XSS']);
    Route::post('pos/save-opening-balance', [PosController::class, 'save_opening_balance'])->name('pos.save_opening_balance')->middleware(['auth', 'XSS']);
    Route::get('pos/add-closing-balance', [PosController::class, 'add_closing_balance'])->name('pos.add_closing_balance')->middleware(['auth', 'XSS']);
    Route::post('pos/save-closing-balance', [PosController::class, 'save_closing_balance'])->name('pos.save_closing_balance')->middleware(['auth', 'XSS']);


    //pos barcode
    Route::get('barcode/pos', [PosController::class, 'barcode'])->name('pos.barcode')->middleware(['auth', 'XSS']);
    Route::get('setting/pos', [PosController::class, 'setting'])->name('pos.setting')->middleware(['auth', 'XSS']);
    Route::post('barcode/settings', [PosController::class, 'BarcodesettingStore'])->name('barcode.setting');
    Route::get('print/pos', [PosController::class, 'printBarcode'])->name('pos.print')->middleware(['auth', 'XSS']);
    Route::post('pos/getproduct', [PosController::class, 'getproduct'])->name('pos.getproduct')->middleware(['auth', 'XSS']);
    Route::get('pos/warehouses-by-branch', [PosController::class, 'getWarehousesByBranch'])->name('pos.warehouses.by.branch')->middleware(['auth', 'XSS']);
    Route::get('pos/restaurant/warehouses-for-product', [PosController::class, 'restaurantWarehousesForProduct'])->name('pos.restaurant.warehouses.for.product')->middleware(['auth', 'XSS']);
    Route::any('pos-receipt', [PosController::class, 'receipt'])->name('pos.receipt')->middleware(['auth', 'XSS']);
    Route::post('/cartdiscount', [PosController::class, 'cartdiscount'])->name('cartdiscount')->middleware(['auth', 'XSS']);

    //for pos print
    Route::get('printview/pos', [PosController::class, 'printView'])->name('pos.printview')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('printview/pos/bill', [PosController::class, 'billView'])->name('pos.bill.view')->middleware(['auth', 'XSS', 'revalidate']);

    Route::get('report/pos', [PosController::class, 'report'])->name('pos.report')->middleware(['auth', 'XSS']);
    Route::get('pos/search-pay-receipts', [PosController::class, 'searchPayReceipts'])->name('pos.search.pay.receipts')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('pos-area/areas-by-branch', [PosAreaController::class, 'getByBranch'])->name('pos-area.by.branch')->middleware(['auth', 'XSS']);
    Route::get('pos-table/tables-by-area', [PosTableController::class, 'getTablesByArea'])->name('pos-table.by.area')->middleware(['auth', 'XSS']);
    Route::get('pos-table/tables-by-branch', [PosTableController::class, 'getTablesByBranch'])->name('pos-table.by.branch')->middleware(['auth', 'XSS']);
    Route::patch('pos-table/{id}/status', [PosTableController::class, 'updateStatus'])->name('pos-table.update-status')->middleware(['auth', 'XSS']);
    Route::post('pos-table/{id}/close', [PosTableController::class, 'closeTable'])->name('pos-table.close')->middleware(['auth', 'XSS']);
    Route::get('pos/restaurant-layout', [PosController::class, 'getRestaurantLayout'])->name('pos.restaurant.layout')->middleware(['auth', 'XSS']);
    Route::get('pos/restaurant/order', [PosController::class, 'getRestaurantOrder'])->name('pos.restaurant.order')->middleware(['auth', 'XSS']);
    Route::get('pos/restaurant/delivery-takeaway-orders', [PosController::class, 'getRestaurantDeliveryTakeawayOrders'])->name('pos.restaurant.delivery-takeaway-orders')->middleware(['auth', 'XSS']);
    Route::post('pos/send-to-production', [PosController::class, 'sendToProduction'])->name('pos.send.to.production')->middleware(['auth', 'XSS']);
    Route::post('pos/restaurant/close-table-if-paid', [PosController::class, 'closeTableIfPaid'])->name('pos.restaurant.close-table-if-paid')->middleware(['auth', 'XSS']);
    Route::get('pos/restaurant/agent-printers', [PosPrinterAgentController::class, 'agentPrinters'])->name('pos.restaurant.agent-printers')->middleware(['auth', 'XSS']);
    Route::get('pos/restaurant/agent-status', [PosPrinterAgentController::class, 'agentStatus'])->name('pos.restaurant.agent-status')->middleware(['auth', 'XSS']);
    Route::post('pos/restaurant/print-config', [PosPrinterAgentController::class, 'savePrintConfig'])->name('pos.restaurant.print-config')->middleware(['auth', 'XSS']);
    Route::post('pos/restaurant/pair-agent', [PosPrinterAgentController::class, 'pairAgent'])->name('pos.restaurant.pair-agent')->middleware(['auth', 'XSS']);
    Route::post('pos/restaurant/print-receipt', [PosPrinterAgentController::class, 'printReceipt'])->name('pos.restaurant.print-receipt')->middleware(['auth', 'XSS']);
    Route::post('pos/cancel-order', [PosController::class, 'cancelOrder'])->name('pos.cancel.order')->middleware(['auth', 'XSS']);
    Route::get('pos/active-shift', [PosController::class, 'getActiveShift'])->name('pos.active.shift')->middleware(['auth', 'XSS']);
    Route::get('pos/branch-open-shifts', [PosController::class, 'getBranchOpenShifts'])->name('pos.branch.open.shifts')->middleware(['auth', 'XSS']);
    Route::resource('pos-area', PosAreaController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('pos-table', PosTableController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('pos-shift/{pos_shift}/report', [PosShiftController::class, 'shiftReport'])->name('pos-shift.report')->middleware(['auth', 'XSS']);
    Route::get('pos-shift/{pos_shift}/close', [PosShiftController::class, 'closeForm'])->name('pos-shift.close-form')->middleware(['auth', 'XSS']);
    Route::post('pos-shift/{pos_shift}/close', [PosShiftController::class, 'close'])->name('pos-shift.close')->middleware(['auth', 'XSS']);
    Route::post('pos-shift/{pos_shift}/send-email', [PosShiftController::class, 'sendClosedEmail'])->name('pos-shift.send-email')->middleware(['auth', 'XSS']);
    Route::post('pos-shift/{pos_shift}/cash-drop', [PosShiftController::class, 'storeCashDrop'])->name('pos-shift.cash-drop')->middleware(['auth', 'XSS']);
    Route::post('pos-shift/{pos_shift}/cash-in', [PosShiftController::class, 'storeCashIn'])->name('pos-shift.cash-in')->middleware(['auth', 'XSS']);
    Route::post('pos-shift/{pos_shift}/cash-out-expense', [PosShiftController::class, 'storeCashOutExpense'])->name('pos-shift.cash-out-expense')->middleware(['auth', 'XSS']);
    Route::get('pos-shift/{pos_shift}/assign-table', [PosShiftController::class, 'assignTableForm'])->name('pos-shift.assign-table')->middleware(['auth', 'XSS']);
    Route::post('pos-shift/{pos_shift}/assign-table', [PosShiftController::class, 'assignTable'])->name('pos-shift.assign-table.store')->middleware(['auth', 'XSS']);
    Route::post('pos-shift/transfer-tables', [PosShiftController::class, 'transferTables'])->name('pos-shift.transfer-tables')->middleware(['auth', 'XSS']);
    Route::get('pos-shift/{pos_shift}/assigned-tables', [PosShiftController::class, 'assignedTables'])->name('pos-shift.assigned-tables')->middleware(['auth', 'XSS']);
    Route::post('pos-shift/open-from-pos', [PosShiftController::class, 'openFromPos'])->name('pos-shift.open-from-pos')->middleware(['auth', 'XSS']);
    Route::post('pos/close-all-shifts-and-eod', [PosShiftController::class, 'closeAllShiftsAndRedirectToEod'])->name('pos.close-all-shifts-and-eod')->middleware(['auth', 'XSS']);
    Route::resource('pos-shift', PosShiftController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('branch-sales-targets', BranchSalesTargetController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('pos-audit-log', [PosAuditLogController::class, 'index'])->name('pos.audit-log.index')->middleware(['auth', 'XSS']);
    Route::get('pos-restaurant-settings', [\App\Http\Controllers\PosRestaurantSettingsController::class, 'index'])->name('pos-restaurant-settings.index')->middleware(['auth', 'XSS']);
    Route::post('pos-restaurant-settings', [\App\Http\Controllers\PosRestaurantSettingsController::class, 'store'])->name('pos-restaurant-settings.store')->middleware(['auth', 'XSS']);
    Route::get('pos-production', [PosProductionController::class, 'index'])->name('pos.production.index')->middleware(['auth', 'XSS']);
    Route::patch('pos-production/line/{line}', [PosProductionController::class, 'updateLineStatus'])->name('pos.production.line.update')->middleware(['auth', 'XSS']);
    Route::post('pos-production/line/{line}/substitute', [PosProductionController::class, 'substituteLineIngredient'])->name('pos.production.line.substitute')->middleware(['auth', 'XSS']);
    Route::resource('pos-printer', PosPrinterController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('{cid?}/pos', [PosController::class, 'index'])->name('poses.index');
    Route::get('pos/retail/{cid?}', [PosController::class, 'index'])->name('pos.index')->defaults('cid', null)->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('pos-restaurant', [PosController::class, 'indexRestaurant'])->name('pos.restaurant.index')->middleware(['auth', 'XSS']);
    Route::post('pos-restaurant/validate-pin', [PosController::class, 'validateRestaurantPin'])->name('pos.restaurant.validate-pin')->middleware(['auth', 'XSS']);
    Route::get('pos-restaurant/switch-user', [PosController::class, 'logoutRestaurantPin'])->name('pos.restaurant.switch-user')->middleware(['auth', 'XSS']);
    Route::post('pos-restaurant/logout-pin', [PosController::class, 'logoutRestaurantPin'])->name('pos.restaurant.logout-pin')->middleware(['auth', 'XSS']);
    Route::post('pos-restaurant/void-line/{line}', [PosController::class, 'voidRestaurantLine'])->name('pos.restaurant.void-line');
    Route::post('pos-restaurant/delete-lines', [PosController::class, 'deleteRestaurantLines'])->name('pos.restaurant.delete-lines');
    Route::post('pos-restaurant/join-tables', [PosController::class, 'joinRestaurantTables'])->name('pos.restaurant.join-tables');
    Route::post('pos-restaurant/move-table', [PosController::class, 'moveRestaurantTable'])->name('pos.restaurant.move-table');
    Route::get('pos-restaurant/transfer-items-data', [PosController::class, 'transferItemsData'])->name('pos.restaurant.transfer-items-data');
    Route::post('pos-restaurant/transfer-items', [PosController::class, 'transferRestaurantItems'])->name('pos.restaurant.transfer-items');
    Route::post('pos-restaurant/split-order', [PosController::class, 'splitRestaurantOrderByItems'])->name('pos.restaurant.split-order');
    Route::get('pos/customer-balance', [PosController::class, 'getCustomerBalance'])->name('pos.customer.balance')->middleware(['auth', 'XSS']);
    Route::get('pos/customer-outstanding-invoices', [PosController::class, 'getCustomerOutstandingInvoices'])->name('pos.customer.outstanding.invoices')->middleware(['auth', 'XSS']);
    Route::get('pos/bank-accounts', [PosController::class, 'getPosBankAccounts'])->name('pos.bank.accounts')->middleware(['auth', 'XSS']);
    Route::post('pos/customer-pay-balance', [PosController::class, 'payCustomerBalance'])->name('pos.customer.pay.balance')->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('pos', PosController::class)->middleware(['auth', 'XSS', 'revalidate']);


    Route::get('product-categories', [ProductServiceCategoryController::class, 'getProductCategories'])->name('product.categories')->middleware(['auth', 'XSS']);
    Route::get('product-categories-json', [ProductServiceCategoryController::class, 'getProductCategoriesJson'])->name('product.categories.json')->middleware(['auth', 'XSS']);
    Route::get('add-to-cart/{id}/{session}', [ProductServiceController::class, 'addToCart'])->middleware(['auth', 'XSS']);
    Route::post('pos/negative-stock-authorize', [PosController::class, 'authorizeNegativeStock'])->name('pos.negative-stock.authorize')->middleware(['auth', 'XSS']);
    Route::patch('update-cart', [ProductServiceController::class, 'updateCart'])->middleware(['auth', 'XSS']);
    Route::delete('remove-from-cart', [ProductServiceController::class, 'removeFromCart'])->middleware(['auth', 'XSS']);

    Route::get('name-search-products', [ProductServiceCategoryController::class, 'searchProductsByName'])->name('name.search.products')->middleware(['auth', 'XSS']);
    Route::get('search-products', [ProductServiceController::class, 'searchProducts'])->name('search.products')->middleware(['auth', 'XSS']);
    Route::get('search-products-sku', [ProductServiceController::class, 'searchProductsSku'])->name('search.products.sku')->middleware(['auth', 'XSS']);


    //warehouse-transfer
    Route::get('warehouse-transfer/{id}/print', [WarehouseTransferController::class, 'print'])->name('warehouse-transfer.print')->middleware(['auth', 'XSS', 'revalidate']);
    Route::resource('warehouse-transfer', WarehouseTransferController::class)->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('warehouse-transfer/getproduct', [WarehouseTransferController::class, 'getproduct'])->name('warehouse-transfer.getproduct')->middleware(['auth', 'XSS']);
    Route::post('warehouse-transfer/getquantity', [WarehouseTransferController::class, 'getquantity'])->name('warehouse-transfer.getquantity')->middleware(['auth', 'XSS']);
    Route::get('warehouse-loss', [WarehouseLossController::class, 'index'])->name('warehouse-loss.index')->middleware(['auth', 'XSS', 'revalidate']);
    Route::post('warehouse-loss', [WarehouseLossController::class, 'store'])->name('warehouse-loss.store')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('warehouse-loss/print-bulk', [WarehouseLossController::class, 'printBulk'])->name('warehouse-loss.print-bulk')->middleware(['auth', 'XSS', 'revalidate']);
    Route::get('warehouse-loss/{id}/print', [WarehouseLossController::class, 'print'])->name('warehouse-loss.print')->middleware(['auth', 'XSS', 'revalidate']);


    // BiometricAttendance
    // Route::post('biometric-setting', [SystemController::class, 'BiometricSetting'])->name('biometric-settings.store')->middleware(['auth', 'XSS']);
    // Route::resource('/biometric-attendance', BiometricAttendanceController::class)->middleware(
    //     [
    //         'auth',
    //         'XSS',
    //     ]
    // );
    // Route::post('/biometric-attendance/sync/{start_date?}/{end_date?}', [BiometricAttendanceController::class, 'AllSync'])->middleware(['auth'])->name('biometric-attendance.allsync');

    //Storage Setting

    Route::post('storage-settings', [SystemController::class, 'storageSettingStore'])->name('storage.setting.store')->middleware(['auth', 'XSS']);

    //appricalStar

    Route::post('/appraisals', [AppraisalController::class, 'empByStar'])->name('empByStar')->middleware(['auth', 'XSS']);
    Route::post('/appraisals1', [AppraisalController::class, 'empByStar1'])->name('empByStar1')->middleware(['auth', 'XSS']);
    Route::post('/getemployee', [AppraisalController::class, 'getemployee'])->name('getemployee');

    //offer Letter

    Route::post('setting/offerlatter/{lang?}', [SystemController::class, 'offerletterupdate'])->name('offerlatter.update');
    Route::get('setting/offerlatter', [SystemController::class, 'companyIndex'])->name('get.offerlatter.language')->middleware(['XSS']);
    Route::get('job-onboard/pdf/{id}', [JobApplicationController::class, 'offerletterPdf'])->name('offerlatter.download.pdf');
    Route::get('job-onboard/doc/{id}', [JobApplicationController::class, 'offerletterDoc'])->name('offerlatter.download.doc');

    //joining Letter
    Route::post('setting/joiningletter/{lang?}', [SystemController::class, 'joiningletterupdate'])->name('joiningletter.update');
    Route::get('setting/joiningletter/', [SystemController::class, 'companyIndex'])->name('get.joiningletter.language')->middleware(['XSS']);
    Route::get('employee/pdf/{id}', [EmployeeController::class, 'joiningletterPdf'])->name('joiningletter.download.pdf');
    Route::get('employee/doc/{id}', [EmployeeController::class, 'joiningletterDoc'])->name('joininglatter.download.doc');

    //Experience Certificate

    Route::post('setting/exp/{lang?}', [SystemController::class, 'experienceCertificateupdate'])->name('experiencecertificate.update');
    Route::get('setting/exp', [SystemController::class, 'companyIndex'])->name('get.experiencecertificate.language')->middleware(['XSS']);
    Route::get('employee/exppdf/{id}', [EmployeeController::class, 'ExpCertificatePdf'])->name('exp.download.pdf');
    Route::get('employee/expdoc/{id}', [EmployeeController::class, 'ExpCertificateDoc'])->name('exp.download.doc');

    //NOC

    Route::post('setting/noc/{lang?}', [SystemController::class, 'NOCupdate'])->name('noc.update');
    Route::get('setting/noc', [SystemController::class, 'companyIndex'])->name('get.noc.language')->middleware(['XSS']);
    Route::get('employee/nocpdf/{id}', [EmployeeController::class, 'NocPdf'])->name('noc.download.pdf');
    Route::get('employee/nocdoc/{id}', [EmployeeController::class, 'NocDoc'])->name('noc.download.doc');

    //Project Reports

    Route::resource('/project_report', ProjectReportController::class)->middleware(['auth', 'XSS']);
    Route::post('/project_report_data', [ProjectReportController::class, 'ajax_data'])->name('projects.ajax')->middleware(['auth', 'XSS']);
    Route::post('/project_report/tasks/{id}', [ProjectReportController::class, 'ajax_tasks_report'])->name('tasks.report.ajaxdata')->middleware(['auth', 'XSS']);
    Route::get('export/task_report/{id}', [ProjectReportController::class, 'export'])->name('project_report.export');

    //project copy module
    Route::get('/project/copy/{id}', [ProjectController::class, 'copyproject'])->name('project.copy')->middleware(['auth', 'XSS']);
    Route::post('/project/copy/store/{id}', [ProjectController::class, 'copyprojectstore'])->name('project.copy.store')->middleware(['auth', 'XSS']);

    //Google Calendar
    Route::any('event/get_event_data', [EventController::class, 'get_event_data'])->name('event.get_event_data')->middleware(['auth', 'XSS']);

    Route::post('setting/google-calender', [SystemController::class, 'saveGoogleCalenderSettings'])->name('google.calender.settings');
    Route::any('holiday/get_holiday_data', [HolidayController::class, 'get_holiday_data'])->name('holiday.get_holiday_data')->middleware(['auth', 'XSS']);
    Route::any('interview-schedule/get_interview_data', [InterviewScheduleController::class, 'get_interview_data'])->name('holiday.get_interview_data')->middleware(['auth', 'XSS']);
    Route::post('calendar/get_task_data', [ProjectTaskController::class, 'get_task_data'])->name('task.calendar.get_task_data')->middleware(['auth', 'XSS']);
    Route::any('zoom-meeting/get_zoom_meeting_data', [ZoomMeetingController::class, 'get_zoom_meeting_data'])->name('zoom-meeting.get_zoom_meeting_data')->middleware(['auth', 'XSS']);

    Route::any('meeting/get_meeting_data', [MeetingController::class, 'get_meeting_data'])->name('meeting.get_meeting_data')->middleware(['auth', 'XSS']);
    Route::get('meeting-calender', [MeetingController::class, 'calender'])->name('meeting.calender')->middleware(['auth', 'XSS']);

    Route::any('event/get_dashboard_event_data', [EventController::class, 'get_dashboard_event_data'])->name('event.get_dashboard_event_data')->middleware(['auth', 'XSS']);

    //branch wise department get in attendance report
    Route::post('reports-monthly-attendance/getdepartment', [ReportController::class, 'getdepartment'])->name('report.attendance.getdepartment')->middleware(['auth', 'XSS']);
    Route::post('reports-monthly-attendance/getemployee', [ReportController::class, 'getemployee'])->name('report.attendance.getemployee')->middleware(['auth', 'XSS']);

    //shared project & copy link
    Route::any('/projects/copy/link/{id}', [ProjectController::class, 'copylinksetting'])->name('projects.copy.link');
    Route::any('/projects{id}/settingcreate', [ProjectController::class, 'copylink_setting_create'])->name('projects.copylink.setting.create');
    Route::get('/shareproject/{lang?}', [ProjectController::class, 'shareproject'])->name('shareproject');

    //User Log
    Route::get('/userlogs', [UserController::class, 'userLog'])->name('user.userlog')->middleware(['auth', 'XSS']);
    Route::get('userlogs/{id}', [UserController::class, 'userLogView'])->name('user.userlogview')->middleware(['auth', 'XSS']);
    Route::delete('userlogs/{id}', [UserController::class, 'userLogDestroy'])->name('user.userlogdestroy')->middleware(['auth', 'XSS']);

    //notification Template
    Route::get('notification_templates/{id?}/{lang?}', [NotificationTemplatesController::class, 'index'])->name('notification_templates.index')->middleware(['auth', 'XSS']);
    Route::get('notification-templates-lang/{id}/{lang?}', [NotificationTemplatesController::class, 'manageNotificationLang'])->name('manage.notification.language')->middleware(['auth', 'XSS']);
    Route::resource('notification-templates', NotificationTemplatesController::class)->middleware(['auth', 'XSS']);

    //Proposal/Invoice/Bill/Purchase/POS - footer notes
    Route::post('system-settings/note', [SystemController::class, 'footerNoteStore'])->name('system.settings.footernote')->middleware(['auth', 'XSS']);

    //AI module
    Route::post('chatgpt-settings', [SystemController::class, 'chatgptSetting'])->name('chatgpt.settings');
    Route::get('generate/{template_name}', [AiTemplateController::class, 'create'])->name('generate');
    Route::post('generate/keywords/{id}', [AiTemplateController::class, 'getKeywords'])->name('generate.keywords');
    Route::post('generate/response', [AiTemplateController::class, 'AiGenerate'])->name('generate.response');

    //AI module for grammar check
    Route::get('grammar/{template}', [AiTemplateController::class, 'grammar'])->name('grammar')->middleware(['auth', 'XSS']);
    Route::post('grammar/response', [AiTemplateController::class, 'grammarProcess'])->name('grammar.response')->middleware(['auth', 'XSS']);

    //IP-Restrication settings
    Route::get('create/ip', [SystemController::class, 'createIp'])->name('create.ip')->middleware(['auth', 'XSS']);
    Route::post('create/ip', [SystemController::class, 'storeIp'])->name('store.ip')->middleware(['auth', 'XSS']);
    Route::get('edit/ip/{id}', [SystemController::class, 'editIp'])->name('edit.ip')->middleware(['auth', 'XSS']);
    Route::post('edit/ip/{id}', [SystemController::class, 'updateIp'])->name('update.ip')->middleware(['auth', 'XSS']);
    Route::delete('destroy/ip/{id}', [SystemController::class, 'destroyIp'])->name('destroy.ip')->middleware(['auth', 'XSS']);

    //lang enable / disable
    Route::post('disable-language', [LanguageController::class, 'disableLang'])->name('disablelanguage')->middleware(['auth', 'XSS']);

    //Expense Module
    Route::get('expense/pdf/{id}', [ExpenseController::class, 'expense'])->name('expense.pdf')->middleware(['XSS', 'revalidate']);
    Route::group(
        [
            'middleware' => [
                'auth',
                'XSS',
                'revalidate',
            ],
        ],
        function () {
            Route::any('expense/customer', [ExpenseController::class, 'customer'])->name('expense.customer');
            Route::post('expense/vender', [ExpenseController::class, 'vender'])->name('expense.vender');
            Route::post('expense/employee', [ExpenseController::class, 'employee'])->name('expense.employee');

            Route::post('expense/product/destroy', [ExpenseController::class, 'productDestroy'])->name('expense.product.destroy');

            Route::post('expense/product', [ExpenseController::class, 'product'])->name('expense.product');
            Route::get('expense/{id}/payment', [ExpenseController::class, 'payment'])->name('expense.payment');
            Route::get('expense/items', [ExpenseController::class, 'items'])->name('expense.items');

            Route::resource('expense', ExpenseController::class);
        }
    );

    Route::get('referral-program/company', [ReferralProgramController::class, 'companyIndex'])->name('referral-program.company');
    Route::resource('referral-program', ReferralProgramController::class);
    Route::get('request-amount-sent/{id}', [ReferralProgramController::class, 'requestedAmountSent'])->name('request.amount.sent');
    Route::get('request-amount-cancel/{id}', [ReferralProgramController::class, 'requestCancel'])->name('request.amount.cancel');
    Route::post('request-amount-store/{id}', [ReferralProgramController::class, 'requestedAmountStore'])->name('request.amount.store');
    Route::get('request-amount/{id}/{status}', [ReferralProgramController::class, 'requestedAmount'])->name('amount.request');
    Route::resource('company-policy', CompanyPolicyController::class)->middleware(['auth', 'XSS']);
    Route::resource('expense-category', ExpenseCategoryController::class)->middleware(['auth', 'XSS']);
    Route::post('expenses/{expense}/approve', [ExpenseRecordController::class, 'approve'])->name('expenses.approve')->middleware(['auth', 'XSS']);
    Route::post('expenses/{expense}/reject', [ExpenseRecordController::class, 'reject'])->name('expenses.reject')->middleware(['auth', 'XSS']);
    Route::post('expenses/{expense}/execute', [ExpenseRecordController::class, 'execute'])->name('expenses.execute')->middleware(['auth', 'XSS']);
    Route::resource('expenses', ExpenseRecordController::class)->middleware(['auth', 'XSS']);

    });


Route::any('/cookie-consent', [SystemController::class, 'CookieConsent'])->name('cookie-consent');
Route::get('payslip/payslipPdf/{id}/{month}', [PaySlipController::class, 'payslipPdf'])->name('payslip.payslipPdf')->middleware(['XSS']);

Route::post('/auto-logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return response()->json(['status' => 'logged_out']);
})->name('auto.logout');

