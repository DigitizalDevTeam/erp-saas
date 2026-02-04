<?php

namespace App\Http\Controllers;

use App\Models\BillProduct;
use App\Models\InvoiceProduct;
use App\Models\ProductService;
use App\Models\ProposalProduct;
use App\Models\Tax;
use App\Models\Customer;
use App\Models\Vender;
use App\Models\PriceLevel;
use Auth;
use Illuminate\Http\Request;

class PriceLevelController extends Controller
{


    public function index()
    {
        if(\Auth::user()->can('manage constant tax'))
        {
            $priceLevels = PriceLevel::where('created_by', '=', \Auth::user()->creatorId())->get();

            return view('priceLevels.index')->with('priceLevels', $priceLevels);
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function create()
    {
        if(\Auth::user()->can('create constant tax'))
        {
            return view('priceLevels.create');
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    public function store(Request $request)
    {
        if(\Auth::user()->can('create constant tax'))
        {
            $validator = \Validator::make(
                $request->all(), [
                   'name' => 'required|max:20',
                   'type' => 'required',
                   'value' => 'required|numeric',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $priceLevel             = new PriceLevel();
            $priceLevel->name       = $request->name;
            $priceLevel->type       = $request->type;
            $priceLevel->value       = $request->value;
            $priceLevel->created_by = \Auth::user()->creatorId();
            $priceLevel->save();

            return redirect()->route('price-levels.index')->with('success', __('Price Level successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function show(Tax $tax)
    {
        return redirect()->route('price-levels.index');
    }


    public function edit(PriceLevel $priceLevel)
    {
        if(\Auth::user()->can('edit constant tax'))
        {
            if($priceLevel->created_by == \Auth::user()->creatorId())
            {
                return view('priceLevels.edit', compact('priceLevel'));
            }
            else
            {
                return response()->json(['error' => __('Permission denied.')], 401);
            }
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }


    public function update(Request $request, PriceLevel $priceLevel)
    {
        if(\Auth::user()->can('edit constant tax'))
        {
            if($priceLevel->created_by == \Auth::user()->creatorId())
            {
                $validator = \Validator::make(
                    $request->all(), [
                        'name' => 'required|max:20',
                        'type' => 'required',
                        'value' => 'required|numeric',
                   ]
                );
                if($validator->fails())
                {
                    $messages = $validator->getMessageBag();

                    return redirect()->back()->with('error', $messages->first());
                }

                $priceLevel->name       = $request->name;
                $priceLevel->type       = $request->type;
                $priceLevel->value       = $request->value;
                $priceLevel->save();

                return redirect()->route('price-levels.index')->with('success', __('Price Level successfully updated.'));
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function destroy(PriceLevel $priceLevel)
    {
        if(\Auth::user()->can('delete constant tax'))
        {
            if($priceLevel->created_by == \Auth::user()->creatorId())
            {
                $customer = Customer::whereRaw("find_in_set('$priceLevel->id',price_level_id)")->first();
                $vender = Vender::whereRaw("find_in_set('$priceLevel->id',price_level_id)")->first();
                $productData  = ProductService::whereRaw("find_in_set('$priceLevel->id',price_level_id)")->first();

                if(!empty($customer) || !empty($vender) || !empty($productData))
                {
                    return redirect()->back()->with('error', __('this price level is already assign to customer or vendor or product&service so please move or remove this price level related data.'));
                }

                $priceLevel->delete();

                return redirect()->route('price-levels.index')->with('success', __('Price level successfully deleted.'));
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
