<?php

namespace Modules\LandingPage\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\LandingPage\Entities\LandingPageSetting;

class FaqController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        if(\Auth::user()->type == 'super admin')
        {
            $settings = LandingPageSetting::landingPageSetting();
            $faqs = json_decode($settings['faqs'], true) ?? [];
            return view('landingpage::landingpage.faq.index', compact('settings','faqs'));
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
    
        /* ------------------ FAQ STATUS (PER LANGUAGE) ------------------ */
        $faqStatus = [];
        foreach ($request->faq_title ?? [] as $lang => $val) {
            $faqStatus[$lang] = isset($request->faq_status[$lang]) ? 'on' : 'off';
        }
        // Merge with existing values to keep other languages intact
        $existingStatus = LandingPageSetting::where('name', 'faq_status')->first();
        $currentStatus = $existingStatus ? json_decode($existingStatus->value, true) : [];
        if (!is_array($currentStatus)) $currentStatus = [];
        $data['faq_status'] = json_encode(array_merge($currentStatus, $faqStatus));
    
        /* ------------------ TEXT FIELDS (MULTI-LANGUAGE) ------------------ */
        $fields = [
            'faq_title',
            'faq_heading',
            'faq_description',
        ];
    
        foreach ($fields as $field) {
            $existing = LandingPageSetting::where('name', $field)->first();
            $currentValues = $existing ? json_decode($existing->value, true) : [];
            if (!is_array($currentValues)) $currentValues = [];
    
            $newValues = $request->$field ?? [];
            if (!is_array($newValues)) $newValues = [];
    
            // Merge new values per language
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
    
        return redirect()->back()->with(['success' => 'FAQ settings updated successfully']);
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



    public function faq_create(){
        $settings = LandingPageSetting::settings();
        $languages = \App\Models\Utility::languages();
        return view('landingpage::landingpage.faq.create',compact('languages'));
    }



    public function faq_store(Request $request){

        $settings = LandingPageSetting::settings();
        $data = json_decode($settings['faqs'], true);

        $datas['faq_questions']= $request->faq_questions;
        $datas['faq_answer']= $request->faq_answer;
        $datas['faq_language']= $request->faq_language;

        $data[] = $datas;
        $data = json_encode($data);
        LandingPageSetting::updateOrCreate(['name' =>  'faqs'],['value' => $data]);

        return redirect()->back()->with(['success'=> 'Faq add successfully']);
    }



    public function faq_edit($key){
        $settings = LandingPageSetting::settings();
        $faqs = json_decode($settings['faqs'], true);
        $faq = $faqs[$key];
        $languages = \App\Models\Utility::languages();
        return view('landingpage::landingpage.faq.edit', compact('faq','key','languages'));
    }



    public function faq_update(Request $request, $key){

        $settings = LandingPageSetting::settings();
        $data = json_decode($settings['faqs'], true);

        $data[$key]['faq_questions'] = $request->faq_questions;
        $data[$key]['faq_answer'] = $request->faq_answer;
        $data[$key]['faq_language'] = $request->faq_language;

        $data = json_encode($data);
        LandingPageSetting::updateOrCreate(['name' =>  'faqs'],['value' => $data]);

        return redirect()->back()->with(['success'=> 'Faq update successfully']);
    }



    public function faq_delete($key)
    {
        $settings = LandingPageSetting::settings();
        $pages = json_decode($settings['faqs'], true);
        unset($pages[$key]);
        LandingPageSetting::updateOrCreate(['name' =>  'faqs'],['value' => $pages]);
        return redirect()->back()->with(['success'=> 'Faq delete successfully']);
    }

}
