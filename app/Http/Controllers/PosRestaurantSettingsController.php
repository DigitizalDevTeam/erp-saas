<?php

namespace App\Http\Controllers;

use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6: POS Restaurant settings – discount approval threshold, low stock threshold.
 */
class PosRestaurantSettingsController extends Controller
{
    public function index()
    {
        if (!Auth::user()->canManagePos()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
        if (!Auth::user()->can('pos restaurant manager') && !Auth::user()->can('pos restaurant full') && !Auth::user()->can('manage pos')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
        $maxDiscount = Utility::getValByName('pos_max_discount_percent');
        $lowStockThreshold = Utility::getValByName('pos_low_stock_threshold');
        return view('pos_restaurant_settings.index', [
            'pos_max_discount_percent' => $maxDiscount !== '' ? $maxDiscount : '10',
            'pos_low_stock_threshold' => $lowStockThreshold !== '' ? $lowStockThreshold : '10',
        ]);
    }

    public function store(Request $request)
    {
        if (!Auth::user()->canManagePos()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
        if (!Auth::user()->can('pos restaurant manager') && !Auth::user()->can('pos restaurant full') && !Auth::user()->can('manage pos')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
        $request->validate([
            'pos_max_discount_percent' => 'nullable|numeric|min:0|max:100',
            'pos_low_stock_threshold' => 'nullable|numeric|min:0',
        ]);
        $creatorId = Auth::user()->creatorId();
        $this->saveSetting($creatorId, 'pos_max_discount_percent', (string) ($request->pos_max_discount_percent ?: 10));
        $this->saveSetting($creatorId, 'pos_low_stock_threshold', (string) ($request->pos_low_stock_threshold ?: 10));
        return redirect()->route('pos-restaurant-settings.index')->with('success', __('Settings saved.'));
    }

    protected function saveSetting(int $createdBy, string $name, string $value): void
    {
        $exists = DB::table('settings')->where('created_by', $createdBy)->where('name', $name)->exists();
        if ($exists) {
            DB::table('settings')->where('created_by', $createdBy)->where('name', $name)->update(['value' => $value]);
        } else {
            DB::table('settings')->insert([
                'name' => $name,
                'value' => $value,
                'created_by' => $createdBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
