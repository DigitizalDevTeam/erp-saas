<?php

namespace App\Http\Controllers;

use App\Imports\FixedAssetImport;
use App\Models\FixedAssetCategory;
use App\Services\FixedAssetAcquisitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Fixed Assets Phase 1 — Excel import of old assets (D4).
 *
 * The template has one row per asset: name, category_code and
 * purchase_cost required; opening accumulated depreciation and
 * remaining life are optional per-asset columns. Valid rows
 * become DRAFT opening-balance assets; bad rows are skipped
 * and listed with their row number.
 */
class FixedAssetImportController extends Controller
{
    public function __construct(
        protected FixedAssetAcquisitionService $acquisitions,
    ) {
    }

    public function form()
    {
        if (! Auth::user()->can('create fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $categories = FixedAssetCategory::forCompany($creatorId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);

        return view('fixed-assets.import', compact('categories'));
    }

    public function template()
    {
        if (! Auth::user()->can('create fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return Excel::download(new class implements \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithHeadings {
            public function array(): array
            {
                return [FixedAssetImport::exampleRow()];
            }

            public function headings(): array
            {
                return FixedAssetImport::headings();
            }
        }, 'fixed-assets-import-template.xlsx');
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $creatorId = (int) Auth::user()->creatorId();
        $import = new FixedAssetImport($this->acquisitions, $creatorId, (int) Auth::id());

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Import failed: :message', ['message' => $e->getMessage()]));
        }

        $result = $import->result;
        $message = __('Imported :created assets. Skipped :skipped rows.', ['created' => $result['created'], 'skipped' => $result['skipped']]);

        if (! empty($result['errors'])) {
            return redirect()->route('fixed-assets.index')->with('success', $message)->with('import_errors', array_slice($result['errors'], 0, 20));
        }

        return redirect()->route('fixed-assets.index')->with('success', $message);
    }
}
