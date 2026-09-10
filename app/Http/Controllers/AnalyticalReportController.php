<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AnalyticalReportController extends Controller
{
    public function powerblox(Request $request)
    {
        if (!\Auth::user()->can('menu.dashboard.accounting.reports.analytical.powerblox')
            && !\Auth::user()->can('manage report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        return view('reports.analytical.powerblox');
    }
}

