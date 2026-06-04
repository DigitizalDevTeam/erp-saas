<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Support\PayrollChartAccountOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Services\HotelAuthorization;
use Illuminate\Validation\Rule;
use Modules\HotelManagement\Entities\HotelAccountingSetting;

class HotelAccountingSettingsController extends Controller
{
    private function canManage(): bool
    {
        return HotelAuthorization::manageAccounting(Auth::user());
    }

    public function index()
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('hotel_accounting_settings')) {
            return redirect()->route('hotel.dashboard')->with('error', __('Run database migrations to enable hotel accounting settings.'));
        }
        $creatorId = (int) Auth::user()->creatorId();
        $setting = HotelAccountingSetting::firstOrNew(['created_by' => $creatorId]);
        $banks = BankAccount::where('created_by', $creatorId)
            ->orderBy('bank_name')
            ->get()
            ->mapWithKeys(function ($b) {
                return [$b->id => trim(($b->bank_name ?? '') . ' ' . ($b->holder_name ?? ''))];
            })
            ->toArray();
        $paymentMethods = ['cash', 'mpesa', 'card', 'bank_transfer', 'e_mola', 'mkesh'];

        $chartGuestArOptions = PayrollChartAccountOptions::listAssetsForCurrentUser(__('—'))->all();
        $chartVatPayableOptions = PayrollChartAccountOptions::listLiabilitiesForCurrentUser(__('—'))->all();
        $chartRevenueOptions = PayrollChartAccountOptions::listIncomeForCurrentUser(__('—'))->all();

        $this->appendChartAccountIfMissing($chartGuestArOptions, $setting->chart_guest_ar_id, $creatorId);
        $this->appendChartAccountIfMissing($chartVatPayableOptions, $setting->chart_vat_payable_id, $creatorId);
        foreach (['chart_room_revenue_id', 'chart_restaurant_revenue_id', 'chart_laundry_revenue_id', 'chart_misc_revenue_id'] as $attr) {
            $this->appendChartAccountIfMissing($chartRevenueOptions, $setting->{$attr}, $creatorId);
        }

        return view('hotelmanagement::accounting_settings.index', compact(
            'setting',
            'banks',
            'paymentMethods',
            'chartGuestArOptions',
            'chartVatPayableOptions',
            'chartRevenueOptions'
        ));
    }

    public function update(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('hotel_accounting_settings')) {
            return redirect()->back()->with('error', __('Run database migrations to enable hotel accounting settings.'));
        }
        $creatorId = (int) Auth::user()->creatorId();

        $coaIdFields = [
            'chart_guest_ar_id',
            'chart_room_revenue_id',
            'chart_restaurant_revenue_id',
            'chart_laundry_revenue_id',
            'chart_misc_revenue_id',
            'chart_vat_payable_id',
        ];
        foreach ($coaIdFields as $field) {
            if ($request->input($field) === '' || $request->input($field) === null) {
                $request->merge([$field => null]);
            }
        }

        $chartAccountOwned = Rule::exists('chart_of_accounts', 'id')->where('created_by', $creatorId);

        $request->validate([
            'chart_guest_ar_id' => ['nullable', 'integer', 'min:1', $chartAccountOwned],
            'chart_room_revenue_id' => ['nullable', 'integer', 'min:1', $chartAccountOwned],
            'chart_restaurant_revenue_id' => ['nullable', 'integer', 'min:1', $chartAccountOwned],
            'chart_laundry_revenue_id' => ['nullable', 'integer', 'min:1', $chartAccountOwned],
            'chart_misc_revenue_id' => ['nullable', 'integer', 'min:1', $chartAccountOwned],
            'chart_vat_payable_id' => ['nullable', 'integer', 'min:1', $chartAccountOwned],
            'default_vat_rate_percent' => 'nullable|numeric|min:0|max:100',
            'pos_invoice_when_charge_to_room' => 'nullable|boolean',
            'bank_map' => 'nullable|array',
            'bank_map.*' => 'nullable|integer|min:1',
        ]);

        $map = [];
        foreach ((array) $request->input('bank_map', []) as $method => $bankId) {
            if ($bankId) {
                $map[$method] = (int) $bankId;
            }
        }

        HotelAccountingSetting::updateOrCreate(
            ['created_by' => $creatorId],
            [
                'chart_guest_ar_id' => $request->input('chart_guest_ar_id') ?: null,
                'chart_room_revenue_id' => $request->input('chart_room_revenue_id') ?: null,
                'chart_restaurant_revenue_id' => $request->input('chart_restaurant_revenue_id') ?: null,
                'chart_laundry_revenue_id' => $request->input('chart_laundry_revenue_id') ?: null,
                'chart_misc_revenue_id' => $request->input('chart_misc_revenue_id') ?: null,
                'chart_vat_payable_id' => $request->input('chart_vat_payable_id') ?: null,
                'default_vat_rate_percent' => $request->input('default_vat_rate_percent', 16),
                'pos_invoice_when_charge_to_room' => $request->boolean('pos_invoice_when_charge_to_room'),
                'folio_payment_bank_map' => $map ?: null,
            ]
        );

        return redirect()->route('hotel.accounting-settings.index')->with('success', __('Hotel accounting settings saved.'));
    }

    /**
     * @param  array<int|string, string>  $options
     */
    private function appendChartAccountIfMissing(array &$options, $accountId, int $creatorId): void
    {
        $id = (int) $accountId;
        if ($id <= 0) {
            return;
        }
        if (isset($options[$id])) {
            return;
        }
        $acc = \App\Models\ChartOfAccount::where('id', $id)->where('created_by', $creatorId)->first();
        if ($acc) {
            $options[$id] = $acc->code . ' — ' . $acc->labelForLocale() . ' (' . __('current mapping') . ')';
        }
    }
}
