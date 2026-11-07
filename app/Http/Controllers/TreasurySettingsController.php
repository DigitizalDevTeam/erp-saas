<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TreasurySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 0 — company settings (plan §5).
 *
 * One row per key in the existing `settings` table per created_by —
 * the same pattern as CostCentreSettings. Base currency, negative-
 * balance policy, reservation policy, document numbering, retention,
 * notification channels, approval defaults, forecast horizons and
 * statement import defaults.
 */
class TreasurySettingsController extends Controller
{
    public function settings()
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.settings', [
            'settings' => TreasurySettings::forCompany($creatorId),
            'users' => User::where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function saveSettings(Request $request)
    {
        if (! Auth::user()->can('manage treasury accounts')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        TreasurySettings::save((int) Auth::user()->creatorId(), $request->all());

        return redirect()->route('treasury.settings')
            ->with('success', __('Treasury settings saved.'));
    }
}
