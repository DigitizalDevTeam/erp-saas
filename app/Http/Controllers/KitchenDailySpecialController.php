<?php

namespace App\Http\Controllers;

use App\Models\KitchenDailySpecial;
use App\Models\ProductService;
use App\Models\ProductServiceUnit;
use App\Services\KitchenDailySpecialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class KitchenDailySpecialController extends Controller
{
    public function __construct(
        protected KitchenDailySpecialService $specials,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage kitchen daily specials')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $this->specials->markExpired($creatorId);

        $date = $request->get('special_date', date('Y-m-d'));
        $specials = KitchenDailySpecial::where('created_by', $creatorId)
            ->with('product', 'sourceProduct')
            ->when($date, fn ($q) => $q->whereDate('special_date', $date))
            ->orderBy('special_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return view('kitchen.daily-specials.index', [
            'specials' => $specials,
            'date' => $date,
            'today' => $this->specials->todaysSpecials($creatorId),
        ]);
    }

    public function create()
    {
        if (! Auth::user()->can('manage kitchen daily specials')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();

        return view('kitchen.daily-specials.create', [
            'dishes' => ProductService::where('created_by', $creatorId)->where('type', 'product')->where('show_on_pos', 1)->orderBy('name')->get(),
            'ingredients' => ProductService::where('created_by', $creatorId)->where('type', 'ingredient')->orderBy('name')->get(),
            'units' => ProductServiceUnit::where('created_by', $creatorId)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage kitchen daily specials')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            if ($request->input('kind') === 'on_the_spot') {
                $special = $this->specials->createOnTheSpot([
                    'special_date' => $request->input('special_date'),
                    'name' => $request->input('name'),
                    'quantity_planned' => $request->input('quantity_planned'),
                    'selling_price' => $request->input('selling_price'),
                    'unit_id' => $request->input('unit_id'),
                    'notes' => $request->input('notes'),
                    'ingredients' => $this->ingredientInput($request),
                ], Auth::user());
            } else {
                $special = $this->specials->createFromDish([
                    'special_date' => $request->input('special_date'),
                    'source_product_id' => $request->input('source_product_id'),
                    'quantity_planned' => $request->input('quantity_planned'),
                    'selling_price' => $request->input('selling_price'),
                    'notes' => $request->input('notes'),
                ], Auth::user());
            }
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('kitchen-daily-specials.index', ['special_date' => $special->special_date->format('Y-m-d')])
            ->with('success', __('Daily special created — it is sellable on the POS today.'));
    }

    /**
     * Weekly planning: pick a week (Monday) and the specials for
     * each day. Weekends are off by default — the form simply leaves
     * them empty — but the chef can add specials on any day.
     */
    public function plan(Request $request)
    {
        if (! Auth::user()->can('manage kitchen daily specials')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $weekStart = $request->get('week_start');
        if ($weekStart === null || strtotime((string) $weekStart) === false) {
            $weekStart = date('Y-m-d', strtotime('monday this week'));
        } else {
            $weekStart = date('Y-m-d', strtotime('monday this week', strtotime((string) $weekStart)));
        }

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $weekDays[] = date('Y-m-d', strtotime($weekStart.' +'.$i.' days'));
        }

        $existing = KitchenDailySpecial::where('created_by', $creatorId)
            ->whereDate('special_date', '>=', $weekDays[0])
            ->whereDate('special_date', '<=', $weekDays[6])
            ->with('product')
            ->orderBy('special_date')
            ->get()
            ->groupBy(fn (KitchenDailySpecial $special) => $special->special_date->format('Y-m-d'));

        return view('kitchen.daily-specials.plan', [
            'week_start' => $weekStart,
            'week_days' => $weekDays,
            'existing' => $existing,
            'dishes' => ProductService::where('created_by', $creatorId)->where('type', 'product')->where('show_on_pos', 1)->orderBy('name')->get(),
            'ingredients' => ProductService::where('created_by', $creatorId)->where('type', 'ingredient')->orderBy('name')->get(),
            'units' => ProductServiceUnit::where('created_by', $creatorId)->orderBy('name')->get(),
        ]);
    }

    public function storePlan(Request $request)
    {
        if (! Auth::user()->can('manage kitchen daily specials')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $days = [];
        foreach ((array) $request->input('days', []) as $dateString => $rows) {
            $dayRows = [];
            foreach ((array) $rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (! empty($row['source_product_id'])) {
                    $dayRows[] = [
                        'source_product_id' => (int) $row['source_product_id'],
                        'quantity_planned' => (float) ($row['quantity_planned'] ?? 0),
                        'selling_price' => isset($row['selling_price']) && $row['selling_price'] !== '' ? (float) $row['selling_price'] : null,
                    ];
                } elseif (! empty($row['name'])) {
                    $dayRows[] = [
                        'name' => $row['name'],
                        'quantity_planned' => (float) ($row['quantity_planned'] ?? 0),
                        'selling_price' => (float) ($row['selling_price'] ?? 0),
                        'unit_id' => ! empty($row['unit_id']) ? (int) $row['unit_id'] : null,
                        'ingredients' => $this->ingredientInput($request, $row),
                    ];
                }
            }
            if ($dayRows !== []) {
                $days[$dateString] = $dayRows;
            }
        }

        try {
            $created = $this->specials->planWeek([
                'week_start' => $request->input('week_start'),
                'days' => $days,
            ], Auth::user());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('kitchen-daily-specials.plan', ['week_start' => $request->input('week_start')])
            ->with('success', __(':count daily specials planned for the week.', ['count' => count($created)]));
    }

    public function cancel(KitchenDailySpecial $kitchenDailySpecial)
    {
        if (! Auth::user()->can('manage kitchen daily specials')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $special = $this->specials->cancel($kitchenDailySpecial, Auth::user());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen-daily-specials.index', ['special_date' => $special->special_date->format('Y-m-d')])
            ->with('success', __('Daily special cancelled — it is no longer sellable on the POS.'));
    }

    /**
     * Normalize the on-the-spot ingredient rows: either the flat
     * `ingredients[]` table, or the rows nested under one plan row.
     */
    protected function ingredientInput(Request $request, ?array $row = null): array
    {
        $source = $row !== null ? ($row['ingredients'] ?? []) : $request->input('ingredients', []);

        $ingredients = [];
        foreach ((array) $source as $ingredient) {
            if (! is_array($ingredient) || empty($ingredient['ingredient_product_id'])) {
                continue;
            }
            $ingredients[] = [
                'ingredient_product_id' => (int) $ingredient['ingredient_product_id'],
                'quantity' => (float) ($ingredient['quantity'] ?? 0),
                'unit_id' => ! empty($ingredient['unit_id']) ? (int) $ingredient['unit_id'] : null,
            ];
        }

        return $ingredients;
    }
}
