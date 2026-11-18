<?php

namespace App\Http\Controllers;

use App\Imports\JournalEntryImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Journal Entry XLSX import.
 *
 * Same permission as creating a journal entry: the import only
 * creates PENDING entries, exactly like the manual form — they
 * still need approval before they post. XLSX only.
 */
class JournalEntryImportController extends Controller
{
    public function form()
    {
        if (! Auth::user()->can('create journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('journalEntry.import');
    }

    public function template()
    {
        if (! Auth::user()->can('create journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return Excel::download(new class implements \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithHeadings {
            public function array(): array
            {
                return JournalEntryImport::exampleRows();
            }

            public function headings(): array
            {
                return JournalEntryImport::headings();
            }
        }, 'journal-entries-import-template.xlsx');
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('create journal entry')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'file' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:10240'],
        ]);

        if ($validator->fails()) {
            // The admin layout only shows the session 'error' toast —
            // validation $errors are never rendered — so a rejected
            // file must come back as a visible session error, or the
            // upload looks like it succeeded. Both checks are needed:
            // 'extensions' rejects a real spreadsheet saved as .csv,
            // 'mimes' rejects a .csv renamed to .xlsx.
            $failed = $validator->failed();
            $message = isset($failed['file']['Extensions']) || isset($failed['file']['Mimes'])
                ? __('Only XLSX files are supported.')
                : $validator->errors()->first('file');

            return redirect()->back()->with('error', $message);
        }

        $import = new JournalEntryImport((int) Auth::user()->creatorId());

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Import failed. Please check the file and try again.'));
        }

        $result = $import->result;
        $message = __('Imported :created journal entries. Skipped :skipped rows.', ['created' => $result['created'], 'skipped' => $result['skipped']]);

        if (! empty($result['errors'])) {
            return redirect()->route('journal-entry.index')->with('success', $message)->with('import_errors', array_slice($result['errors'], 0, 20));
        }

        return redirect()->route('journal-entry.index')->with('success', $message);
    }
}
