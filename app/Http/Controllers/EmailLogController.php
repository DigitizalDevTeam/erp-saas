<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    public function index(Request $request)
    {
        $user = \Auth::user();

        if (! in_array($user->type, ['super admin', 'company'], true)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $query = EmailLog::query()
            ->with('user')
            ->orderByDesc('created_at');

        if ($user->type !== 'super admin') {
            $query->where('created_by', $user->creatorId());
        }

        if ($request->filled('status') && in_array($request->status, [EmailLog::STATUS_SUCCESS, EmailLog::STATUS_FAILED], true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('source')) {
            $query->where('source', 'like', '%' . $request->source . '%');
        }

        if ($request->filled('recipient')) {
            $query->where('recipient', 'like', '%' . $request->recipient . '%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->paginate(50)->withQueryString();

        return view('email_logs.index', compact('logs'));
    }
}
