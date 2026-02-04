<?php

namespace Modules\LandingPage\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\LandingPage\Entities\LandingPageSetting;

class PricingPlanController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        if(\Auth::user()->type == 'super admin')
        {
            $settings = LandingPageSetting::settings();
            return view('landingpage::landingpage.pricing_plan', compact('settings'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('landingpage::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $data = [];
    
        /* ------------------ PLAN STATUS (PER LANGUAGE) ------------------ */
        $planStatus = [];
        foreach ($request->plan_title ?? [] as $lang => $val) {
            // If checkbox for this language is set, mark 'on', else 'off'
            $planStatus[$lang] = isset($request->plan_status[$lang]) ? 'on' : 'off';
        }
        // Merge with existing values to avoid overwriting other languages
        $existingStatus = LandingPageSetting::where('name', 'plan_status')->first();
        $currentStatus = $existingStatus ? json_decode($existingStatus->value, true) : [];
        if (!is_array($currentStatus)) $currentStatus = [];
        $data['plan_status'] = json_encode(array_merge($currentStatus, $planStatus));
    
        /* ------------------ TEXT FIELDS (MULTI-LANGUAGE) ------------------ */
        $fields = [
            'plan_title',
            'plan_heading',
            'plan_description',
        ];
    
        foreach ($fields as $field) {
            $existing = LandingPageSetting::where('name', $field)->first();
            $currentValues = $existing ? json_decode($existing->value, true) : [];
            if (!is_array($currentValues)) $currentValues = [];
    
            $newValues = $request->$field ?? [];
            if (!is_array($newValues)) $newValues = [];
    
            // Merge new values per language, keep existing others
            $mergedValues = array_merge($currentValues, $newValues);
    
            $data[$field] = json_encode($mergedValues);
        }
    
        /* ------------------ SAVE TO DB ------------------ */
        foreach ($data as $key => $value) {
            LandingPageSetting::updateOrCreate(
                ['name' => $key],
                ['value' => $value]
            );
        }
    
        return redirect()->back()->with(['success' => 'Plan updated successfully']);
    }


    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('landingpage::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('landingpage::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        //
    }
}
