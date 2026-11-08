<?php

namespace App\Http\Controllers;

use App\Services\FixedAssetSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Fixed Assets Settings — Phase 0 (plan §12b D2/D5/D7/D8).
 *
 * Base currency, reference prefix, asset clearing account
 * code, label size and the approval threshold. Stored in the
 * existing `settings` table per created_by — no new table.
 */
class FixedAssetSettingsController extends Controller
{
    public function settings()
    {
        if (! Auth::user()->can('manage fixed asset settings')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $settings = FixedAssetSettings::forCompany($creatorId);

        return view('fixed-assets.settings', compact('settings'));
    }

    public function saveSettings(Request $request)
    {
        if (! Auth::user()->can('manage fixed asset settings')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            FixedAssetSettings::KEY_BASE_CURRENCY => ['nullable', 'string', 'size:3'],
            FixedAssetSettings::KEY_REFERENCE_PREFIX => ['nullable', 'string', 'max:10'],
            FixedAssetSettings::KEY_CLEARING_ACCOUNT_CODE => ['nullable', 'string', 'max:50'],
            FixedAssetSettings::KEY_LABEL_SIZE => ['nullable', Rule::in(FixedAssetSettings::LABEL_SIZES)],
            FixedAssetSettings::KEY_APPROVAL_THRESHOLD => ['nullable', 'numeric', 'min:0'],
        ]);

        $creatorId = (int) Auth::user()->creatorId();
        FixedAssetSettings::save($creatorId, $request->only(array_keys(FixedAssetSettings::DEFAULTS)));

        return redirect()->route('fixed-assets.settings')
            ->with('success', __('Fixed asset settings successfully saved.'));
    }
}
