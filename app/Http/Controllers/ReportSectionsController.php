<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ReportSectionsController extends Controller
{
    public function monthly(Request $request)
    {
        if (!\Auth::user()->can('trial balance report') && !\Auth::user()->can('tax report') && !\Auth::user()->can('manage report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        return view('reports.sections.monthly');
    }

    public function management(Request $request)
    {
        if (!\Auth::user()->can('manage report') &&
            !\Auth::user()->can('income report') &&
            !\Auth::user()->can('expense report') &&
            !\Auth::user()->can('tax report') &&
            !\Auth::user()->can('invoice report') &&
            !\Auth::user()->can('bill report') &&
            !\Auth::user()->can('stock report') &&
            !\Auth::user()->can('statement report') &&
            !\Auth::user()->can('income vs expense report')
        ) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        return view('reports.sections.management');
    }

    public function analytical(Request $request)
    {
        if (!\Auth::user()->can('manage report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        return view('reports.sections.analytical');
    }
}

