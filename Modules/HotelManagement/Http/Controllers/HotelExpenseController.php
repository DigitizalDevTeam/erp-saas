<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelExpense;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelFolioPaymentService;
use Modules\HotelManagement\Services\HotelPettyCashService;

class HotelExpenseController extends Controller
{
    public function __construct(
        private readonly HotelPettyCashService $pettyCashService,
    ) {
    }

    private function canView(): bool
    {
        return HotelAuthorization::viewFrontdesk(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageFrontdesk(Auth::user());
    }

    public function index(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! Schema::hasTable('hotel_expenses')) {
            return redirect()->back()->with('error', __('Run migrations to enable hotel expenses.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $category = $request->get('category', HotelExpense::CATEGORY_LAUNDRY);

        $query = HotelExpense::where('created_by', $creatorId)
            ->with(['room', 'recorder'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if ($category !== '' && $category !== 'all') {
            $query->where('category', $category);
        }

        $expenses = $query->limit(200)->get();
        $categoryOptions = HotelExpense::categoryOptions();
        $canManage = $this->canManage();

        return view('hotelmanagement::expenses.index', compact(
            'expenses',
            'category',
            'categoryOptions',
            'canManage'
        ));
    }

    public function create(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $category = $request->get('category', HotelExpense::CATEGORY_LAUNDRY);
        $categoryOptions = HotelExpense::categoryOptions();
        $paymentMethods = HotelFolioPaymentService::paymentMethodOptions();
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())
            ->orderBy('room_no')
            ->pluck('room_no', 'id');

        return view('hotelmanagement::expenses.create', compact(
            'category',
            'categoryOptions',
            'paymentMethods',
            'rooms'
        ));
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'category' => 'required|in:'.implode(',', array_keys(HotelExpense::categoryOptions())),
            'title' => 'required|string|max:191',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'payment_method' => 'nullable|string|max:50',
            'vendor' => 'nullable|string|max:191',
            'reference' => 'nullable|string|max:191',
            'hotel_room_id' => 'nullable|exists:hotel_rooms,id',
            'notes' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $creatorId = (int) Auth::user()->creatorId();

        if ($request->filled('hotel_room_id')) {
            $roomOwned = HotelRoom::where('id', $request->hotel_room_id)
                ->where('created_by', $creatorId)
                ->exists();
            if (! $roomOwned) {
                return redirect()->back()->withInput()->with('error', __('Invalid room selected.'));
            }
        }

        $expense = HotelExpense::create([
            'branch_id' => Auth::user()->activeBranchContextId(),
            'category' => $request->category,
            'title' => $request->title,
            'amount' => (float) $request->amount,
            'expense_date' => $request->expense_date,
            'payment_method' => $request->payment_method ?: 'cash',
            'vendor' => $request->vendor,
            'reference' => $request->reference,
            'hotel_room_id' => $request->hotel_room_id ?: null,
            'notes' => $request->notes,
            'created_by' => $creatorId,
            'recorded_by' => Auth::id(),
        ]);

        $this->pettyCashService->syncExpenseToShift($expense, $creatorId, (int) Auth::id());

        $message = __('Expense recorded successfully.');
        if ($this->pettyCashService->isCashMethod($expense->payment_method) && $expense->fresh()->hotel_cashier_shift_id) {
            $message = __('Expense recorded and deducted from petty cash (open cashier shift).');
        } elseif ($this->pettyCashService->isCashMethod($expense->payment_method)) {
            $message = __('Expense recorded. Open a cashier shift to deduct cash from petty cash float.');
        }

        return redirect()
            ->route('hotel.expenses.index', ['category' => $request->category])
            ->with('success', $message);
    }

    public function edit(HotelExpense $expense)
    {
        if (! $this->canManage() || ! $this->ownsExpense($expense)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $categoryOptions = HotelExpense::categoryOptions();
        $paymentMethods = HotelFolioPaymentService::paymentMethodOptions();
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())
            ->orderBy('room_no')
            ->pluck('room_no', 'id');

        return view('hotelmanagement::expenses.edit', compact(
            'expense',
            'categoryOptions',
            'paymentMethods',
            'rooms'
        ));
    }

    public function update(Request $request, HotelExpense $expense)
    {
        if (! $this->canManage() || ! $this->ownsExpense($expense)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'category' => 'required|in:'.implode(',', array_keys(HotelExpense::categoryOptions())),
            'title' => 'required|string|max:191',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'payment_method' => 'nullable|string|max:50',
            'vendor' => 'nullable|string|max:191',
            'reference' => 'nullable|string|max:191',
            'hotel_room_id' => 'nullable|exists:hotel_rooms,id',
            'notes' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $creatorId = (int) Auth::user()->creatorId();

        if ($request->filled('hotel_room_id')) {
            $roomOwned = HotelRoom::where('id', $request->hotel_room_id)
                ->where('created_by', $creatorId)
                ->exists();
            if (! $roomOwned) {
                return redirect()->back()->withInput()->with('error', __('Invalid room selected.'));
            }
        }

        $expense->update([
            'category' => $request->category,
            'title' => $request->title,
            'amount' => (float) $request->amount,
            'expense_date' => $request->expense_date,
            'payment_method' => $request->payment_method ?: 'cash',
            'vendor' => $request->vendor,
            'reference' => $request->reference,
            'hotel_room_id' => $request->hotel_room_id ?: null,
            'notes' => $request->notes,
        ]);

        $this->pettyCashService->syncExpenseToShift($expense->fresh(), $creatorId, (int) Auth::id());

        return redirect()
            ->route('hotel.expenses.index', ['category' => $expense->category])
            ->with('success', __('Expense updated successfully.'));
    }

    public function destroy(HotelExpense $expense)
    {
        if (! $this->canManage() || ! $this->ownsExpense($expense)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $category = $expense->category;
        $creatorId = (int) Auth::user()->creatorId();
        $this->pettyCashService->detachExpense($expense, $creatorId);
        $expense->delete();

        return redirect()
            ->route('hotel.expenses.index', ['category' => $category])
            ->with('success', __('Expense deleted successfully.'));
    }

    private function ownsExpense(HotelExpense $expense): bool
    {
        return (int) $expense->created_by === (int) Auth::user()->creatorId();
    }
}
