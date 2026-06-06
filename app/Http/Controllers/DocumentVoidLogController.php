<?php

namespace App\Http\Controllers;

use App\Models\DocumentVoidLog;
use Illuminate\Http\Request;

class DocumentVoidLogController extends Controller
{
    public function index(Request $request)
    {
        $user = \Auth::user();

        if (! in_array($user->type, ['super admin', 'company'], true)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $query = DocumentVoidLog::query()
            ->with('user')
            ->orderByDesc('created_at');

        if ($user->type !== 'super admin') {
            $query->where('created_by', $user->creatorId());
        }

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->paginate(50)->withQueryString();

        return view('document_void_logs.index', compact('logs'));
    }
}
