<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\HotelReservationCreatedMail;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Support\LoggedMail;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFloor;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelAddonService;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelCheckInService;
use Modules\HotelManagement\Services\HotelReservationPricingService;
use Modules\HotelManagement\Services\HotelRoomStatusService;
use Modules\HotelManagement\Support\HotelNationalityOptions;

class HotelReservationController extends Controller
{
    public function __construct(
        private readonly HotelReservationPricingService $pricingService,
        private readonly HotelAddonService $addonService,
        private readonly HotelRoomStatusService $roomStatusService,
        private readonly HotelCheckInService $checkInService,
    ) {
    }

    private function canView(): bool
    {
        return HotelAuthorization::viewReservations(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageReservations(Auth::user());
    }

    protected function roomHasConflict(int $roomId, Carbon $checkInAt, Carbon $checkOutAt, ?int $ignoreReservationId = null): bool
    {
        return HotelReservation::where('created_by', Auth::user()->creatorId())
            ->where('hotel_room_id', $roomId)
            ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
            ->when($ignoreReservationId, fn ($q) => $q->where('id', '!=', $ignoreReservationId))
            ->whereRaw(
                "COALESCE(check_in_at, CONCAT(check_in_date, ' 00:00:00')) < ? AND COALESCE(check_out_at, CONCAT(check_out_date, ' 23:59:59')) > ?",
                [$checkOutAt->format('Y-m-d H:i:s'), $checkInAt->format('Y-m-d H:i:s')]
            )
            ->exists();
    }

    protected function roomTypeOptions()
    {
        $creatorId = Auth::user()->creatorId();
        if (! HotelRoomType::where('created_by', $creatorId)->exists()) {
            HotelRoomType::create([
                'name' => 'Standard',
                'unit_category' => 'room',
                'base_price' => 0,
                'nightly_rate' => 0,
                'monthly_rate' => 0,
                'default_billing_mode' => 'nightly',
                'max_occupancy' => 2,
                'is_active' => true,
                'created_by' => $creatorId,
            ]);
        }

        return HotelRoomType::where('created_by', $creatorId)
            ->where('is_active', true)
            ->pluck('name', 'id');
    }

    protected function floorOptions()
    {
        return HotelFloor::where('created_by', Auth::user()->creatorId())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('level_number')
            ->pluck('name', 'id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function reservationFormPayload(): array
    {
        $creatorId = Auth::user()->creatorId();
        $roomTypes = $this->roomTypeOptions();
        $roomsCollection = HotelRoom::where('created_by', $creatorId)
            ->with(['roomType', 'floor'])
            ->orderBy('room_no')
            ->get(['id', 'room_no', 'hotel_room_type_id', 'hotel_floor_id', 'status', 'override_price', 'monthly_override_price']);

        $roomsByType = $roomsCollection->groupBy('hotel_room_type_id')
            ->map(fn ($group) => $group->map(fn ($room) => [
                'id' => $room->id,
                'room_no' => $room->room_no,
                'status' => $room->status,
                'floor_name' => $room->floor->name ?? null,
                'override_price' => $room->override_price,
                'monthly_override_price' => $room->monthly_override_price,
            ])->values())
            ->toArray();

        $roomTypesMeta = HotelRoomType::where('created_by', $creatorId)
            ->get(['id', 'unit_category', 'nightly_rate', 'monthly_rate', 'default_billing_mode', 'base_price', 'max_occupancy'])
            ->mapWithKeys(fn ($type) => [$type->id => [
                'unit_category' => $type->unit_category,
                'nightly_rate' => (float) ($type->nightly_rate ?? $type->base_price ?? 0),
                'monthly_rate' => (float) ($type->monthly_rate ?? 0),
                'default_billing_mode' => $type->default_billing_mode ?? 'nightly',
                'max_occupancy' => (int) ($type->max_occupancy ?? 2),
            ]])
            ->toArray();

        return [
            'roomTypes' => $roomTypes,
            'rooms' => $roomsCollection->pluck('room_no', 'id'),
            'roomsByType' => $roomsByType,
            'roomTypeBasePrices' => collect($roomTypesMeta)->mapWithKeys(fn ($meta, $id) => [$id => $meta['nightly_rate']])->toArray(),
            'roomTypesMeta' => $roomTypesMeta,
            'floors' => $this->floorOptions(),
            'nationalityOptions' => HotelNationalityOptions::all(),
            'maxDiscountPercent' => $this->pricingService->maxDiscountPercentForCurrentUser(),
            'addonsCatalog' => Schema::hasTable('hotel_addons')
                ? HotelAddon::where('created_by', $creatorId)->where('is_active', true)->orderBy('type')->orderBy('name')->get()
                : collect(),
        ];
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $today = Carbon::today()->toDateString();
        $reservations = HotelReservation::where('created_by', $creatorId)
            ->with(['guest', 'room', 'roomType', 'ratePlan', 'stay.folio.payments'])
            ->orderByDesc('id')
            ->get();

        $kpis = [
            'today' => HotelReservation::where('created_by', $creatorId)->whereDate('check_in_date', $today)->count(),
            'confirmed' => HotelReservation::where('created_by', $creatorId)->whereIn('status', [HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])->count(),
            'pending' => HotelReservation::where('created_by', $creatorId)->where('status', HotelReservation::STATUS_PENDING)->count(),
            'no_shows' => HotelReservation::where('created_by', $creatorId)->where('status', HotelReservation::STATUS_PENDING)->whereDate('check_in_date', '<', $today)->count(),
            'revenue' => (float) \DB::table('hotel_folios')
                ->join('hotel_stays', 'hotel_stays.id', '=', 'hotel_folios.hotel_stay_id')
                ->join('hotel_reservations', 'hotel_reservations.id', '=', 'hotel_stays.hotel_reservation_id')
                ->where('hotel_reservations.created_by', $creatorId)
                ->whereIn('hotel_reservations.status', [HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN, HotelReservation::STATUS_CHECKED_OUT])
                ->sum('hotel_folios.grand_total'),
        ];

        $sourceCounts = HotelReservation::where('created_by', $creatorId)
            ->selectRaw('COALESCE(NULLIF(source, ""), "manual") as source, COUNT(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $sourceLabels = $sourceCounts->keys()->map(fn ($s) => __(\Illuminate\Support\Str::title(str_replace('_', ' ', $s))))->values();
        $sourceSeries = $sourceCounts->values();
        $totalSource = (int) $sourceCounts->sum();

        $daySummary = [
            'check_ins' => HotelReservation::where('created_by', $creatorId)->whereDate('check_in_date', $today)->count(),
            'check_outs' => HotelReservation::where('created_by', $creatorId)->whereDate('check_out_date', $today)->count(),
            'reservations' => HotelReservation::where('created_by', $creatorId)->whereDate('created_at', $today)->count(),
            'revenue' => (float) HotelFolio::where('created_by', $creatorId)->whereDate('created_at', $today)->sum('grand_total'),
            'occupancy_rate' => (function () use ($creatorId) {
                $rt = HotelRoom::where('created_by', $creatorId)->count();
                $occ = HotelRoom::where('created_by', $creatorId)->where('status', HotelRoom::STATUS_OCCUPIED)->count();
                return $rt > 0 ? round(($occ / $rt) * 100, 1) : 0;
            })(),
        ];

        $recent = $reservations->take(8);

        return view('hotelmanagement::reservations.index', compact('reservations', 'kpis', 'sourceLabels', 'sourceSeries', 'totalSource', 'daySummary', 'recent'));
    }

    public function show(HotelReservation $reservation)
    {
        if (! $this->canView() || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $reservation->load([
            'guest.customer',
            'room.roomType',
            'roomType',
            'ratePlan',
            'group',
            'reservationAddons.addon',
            'stay.folio.lines',
            'stay.folio.payments' => fn ($q) => $q->with('creator')->orderBy('id'),
        ]);

        $timeline = $this->buildReservationTimeline($reservation);
        $canManage = $this->canManage();
        $totals = $this->pricingService->reservationTotals($reservation);

        return view('hotelmanagement::reservations.show', compact('reservation', 'timeline', 'canManage', 'totals'));
    }

    /**
     * @return array<int, array{at: string, label: string, detail: string|null, icon: string}>
     */
    protected function buildReservationTimeline(HotelReservation $reservation): array
    {
        $events = [];

        $events[] = [
            'at' => $reservation->created_at?->format('Y-m-d H:i') ?? '',
            'label' => __('Reservation created'),
            'detail' => $reservation->reservation_no,
            'icon' => 'ti-calendar-plus',
        ];

        if ($reservation->guest_email_sent_at) {
            $events[] = [
                'at' => $reservation->guest_email_sent_at->format('Y-m-d H:i'),
                'label' => __('Confirmation email sent'),
                'detail' => $reservation->guest->email ?? null,
                'icon' => 'ti-mail',
            ];
        }

        $stay = $reservation->stay;
        if ($stay?->check_in_at) {
            $events[] = [
                'at' => Carbon::parse($stay->check_in_at)->format('Y-m-d H:i'),
                'label' => __('Checked in'),
                'detail' => $reservation->room ? __('Room') . ' ' . $reservation->room->room_no : null,
                'icon' => 'ti-login',
            ];
        }

        $folio = $stay?->folio;
        if ($folio) {
            $events[] = [
                'at' => $folio->created_at?->format('Y-m-d H:i') ?? '',
                'label' => __('Room bill opened'),
                'detail' => $folio->folio_no,
                'icon' => 'ti-receipt',
            ];

            foreach ($folio->lines as $line) {
                $events[] = [
                    'at' => $line->created_at?->format('Y-m-d H:i') ?? '',
                    'label' => __('Charge posted'),
                    'detail' => $line->description . ' — ' . Auth::user()->priceFormat($line->line_total),
                    'icon' => 'ti-plus',
                ];
            }

            foreach ($folio->payments as $payment) {
                $events[] = [
                    'at' => $payment->created_at?->format('Y-m-d H:i') ?? '',
                    'label' => __('Payment received'),
                    'detail' => Auth::user()->priceFormat($payment->amount) . ($payment->method ? ' (' . $payment->method . ')' : ''),
                    'icon' => 'ti-cash',
                ];
            }
        }

        if ($stay?->checked_out_at) {
            $events[] = [
                'at' => Carbon::parse($stay->checked_out_at)->format('Y-m-d H:i'),
                'label' => __('Checked out'),
                'detail' => $stay->notes,
                'icon' => 'ti-logout',
            ];
        }

        if ($reservation->status === HotelReservation::STATUS_CANCELLED) {
            $events[] = [
                'at' => $reservation->updated_at?->format('Y-m-d H:i') ?? '',
                'label' => __('Reservation cancelled'),
                'detail' => null,
                'icon' => 'ti-ban',
            ];
        }

        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $events;
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::reservations.create', $this->reservationFormPayload());
    }

    /**
     * @return HotelReservation|RedirectResponse
     */
    protected function persistReservation(Request $request, bool $forceConfirmed = false)
    {
        $validator = \Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:191',
            'check_in_at' => 'required|date',
            'check_out_at' => 'required|date|after:check_in_at',
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'hotel_room_id' => 'nullable|exists:hotel_rooms,id',
            'source' => 'required|in:manual,walkin,online,ota',
            'status' => 'required|in:pending,confirmed,checked_in,checked_out,cancelled',
            'billing_mode' => 'required|in:nightly,monthly',
            'id_type' => 'nullable|in:id,passport,letter',
            'passport_number' => 'nullable|string|max:100',
            'visa_number' => 'nullable|string|max:100',
            'nationality' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'preferred_payment_method' => 'nullable|string|max:50',
            'credit_limit' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'discount_reason' => 'nullable|string|max:1000',
            'preferred_hotel_floor_id' => 'nullable|exists:hotel_floors,id',
            'estimated_arrival_time' => 'nullable|date_format:H:i',
            'meal_plan' => 'nullable|in:room_only,breakfast,half_board,full_board,all_inclusive',
            'booking_guarantee' => 'nullable|in:none,card,deposit,voucher',
            'payment_method' => 'nullable|string|max:50',
            'infants' => 'nullable|integer|min:0',
            'special_requests' => 'nullable|array',
            'special_requests.*' => 'string|max:64',
            'internal_notes' => 'nullable|string|max:5000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        if ($request->status === HotelReservation::STATUS_CHECKED_IN) {
            $request->merge(['status' => HotelReservation::STATUS_CONFIRMED]);
        }

        $creatorId = Auth::user()->creatorId();
        $checkInAt = Carbon::parse($request->check_in_at);
        $checkOutAt = Carbon::parse($request->check_out_at);
        if (! empty($request->hotel_room_id) && $this->roomHasConflict((int) $request->hotel_room_id, $checkInAt, $checkOutAt)) {
            return redirect()->back()->withInput()->with('error', __('Selected room is not available for the chosen check-in/check-out time.'));
        }

        if (! empty($request->hotel_room_id)) {
            $assignRoom = HotelRoom::where('created_by', $creatorId)->find((int) $request->hotel_room_id);
            if ($assignRoom) {
                $block = $this->roomStatusService->assignmentBlockReason($assignRoom);
                if ($block) {
                    return redirect()->back()->withInput()->with('error', $block);
                }
            }
        }

        $quote = $this->pricingService->quote(
            $creatorId,
            (int) $request->hotel_room_type_id,
            $request->filled('hotel_room_id') ? (int) $request->hotel_room_id : null,
            $checkInAt,
            $checkOutAt,
            (string) $request->billing_mode
        );

        $discountAmount = round((float) ($request->discount_amount ?: 0), 2);
        if ($discountAmount > 0 && blank($request->discount_reason)) {
            return redirect()->back()->withInput()->with('error', __('Discount justification is required.'));
        }
        if (! $this->pricingService->validateDiscountAmount($discountAmount, (float) $quote['subtotal'])) {
            $maxPercent = rtrim(rtrim(number_format($this->pricingService->maxDiscountPercentForCurrentUser(), 2, '.', ''), '0'), '.');
            return redirect()->back()->withInput()->with('error', __('Discount exceeds your allowed limit of :percent%.', ['percent' => $maxPercent]));
        }

        $addonRows = $this->addonService->normalizeAddonInput($request->input('addons'));
        $occupancyError = $this->addonService->validateOccupancyForBooking(
            (int) $request->hotel_room_type_id,
            (int) ($request->adults ?: 1),
            (int) ($request->children ?: 0),
            $addonRows,
            $creatorId
        );
        if ($occupancyError) {
            return redirect()->back()->withInput()->with('error', $occupancyError);
        }

        $preferredFloor = null;
        if ($request->filled('preferred_hotel_floor_id')) {
            $preferredFloor = HotelFloor::where('created_by', $creatorId)->find((int) $request->preferred_hotel_floor_id);
        }

        $guestId = $request->filled('hotel_guest_id') ? (int) $request->hotel_guest_id : null;
        $guest = $guestId ? HotelGuest::where('created_by', $creatorId)->find($guestId) : null;
        $guestData = [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'birth_date' => $request->birth_date,
            'id_type' => $request->id_type,
            'id_number' => $request->id_number,
            'passport_number' => $request->passport_number,
            'visa_number' => $request->visa_number,
            'nationality' => $request->nationality,
            'country' => $request->country,
            'preferred_room_type_id' => $request->preferred_room_type_id ?: $request->hotel_room_type_id,
            'preferred_floor' => $preferredFloor?->name,
            'preferred_hotel_floor_id' => $preferredFloor?->id,
            'preferred_payment_method' => $request->preferred_payment_method,
            'credit_limit' => $request->credit_limit ?: 0,
            'notes' => $request->guest_notes,
            'allergies' => $request->allergies,
            'preferences' => $request->preferences,
            'drink_preferences' => $request->drink_preferences,
            'company_name' => $request->company_name,
            'is_vip' => $request->has('is_vip'),
        ];
        if ($guest) {
            $guest->update($guestData);
        } else {
            $guest = HotelGuest::create(array_merge($guestData, ['created_by' => $creatorId]));
        }

        $status = $forceConfirmed ? HotelReservation::STATUS_CONFIRMED : $request->status;
        $reservation = HotelReservation::create([
            'reservation_no' => 'RSV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
            'hotel_guest_id' => $guest->id,
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'hotel_room_id' => $request->hotel_room_id,
            'hotel_rate_plan_id' => $quote['rate_plan_id'] ?? null,
            'hotel_group_id' => $request->filled('hotel_group_id') ? (int) $request->hotel_group_id : null,
            'branch_id' => Auth::user()->activeBranchContextId(),
            'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
            'estimated_arrival_time' => $request->estimated_arrival_time ?: null,
            'parking_requested' => $request->has('parking_requested'),
            'check_in_date' => $checkInAt->toDateString(),
            'check_out_date' => $checkOutAt->toDateString(),
            'adults' => (int) ($request->adults ?: 1),
            'children' => (int) ($request->children ?: 0),
            'infants' => (int) ($request->infants ?: 0),
            'source' => $request->source,
            'status' => $status,
            'billing_mode' => $quote['billing_mode'],
            'meal_plan' => $request->meal_plan,
            'booking_guarantee' => $request->booking_guarantee,
            'payment_method' => $request->payment_method,
            'rate_per_night' => $quote['rate_amount'],
            'discount_amount' => $discountAmount,
            'discount_percent' => $request->filled('discount_percent') ? (float) $request->discount_percent : null,
            'discount_reason' => $discountAmount > 0 ? $request->discount_reason : null,
            'notes' => $request->notes,
            'special_requests' => array_values(array_filter((array) $request->input('special_requests', []))),
            'internal_notes' => $request->internal_notes,
            'created_by' => $creatorId,
        ]);

        $this->addonService->syncReservationAddons($reservation, $addonRows, $creatorId);

        $reservation->load(['guest', 'roomType', 'room', 'ratePlan', 'reservationAddons']);
        $this->sendReservationEmail($reservation);

        return $reservation;
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $result = $this->persistReservation($request, false);
        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return redirect()->route('hotel.reservations.index')->with('success', __('Reservation created successfully.'));
    }

    public function storeAndCheckIn(Request $request, HotelFrontdeskController $frontdesk)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->merge(['status' => HotelReservation::STATUS_CONFIRMED]);
        $result = $this->persistReservation($request, true);
        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $reservation = $result;
        if (! $reservation->hotel_room_id) {
            return redirect()->route('hotel.reservations.edit', $reservation)
                ->with('error', __('Assign a room before check-in, then use Check-in from the list.'));
        }

        return $frontdesk->checkIn($reservation);
    }

    public function edit(HotelReservation $reservation)
    {
        if ((! $this->canManage()) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $reservation->load(['guest', 'ratePlan', 'reservationAddons.addon']);

        return view('hotelmanagement::reservations.edit', array_merge(
            ['reservation' => $reservation],
            $this->reservationFormPayload()
        ));
    }

    public function update(Request $request, HotelReservation $reservation)
    {
        if ((! $this->canManage()) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $isAdmin = HotelAuthorization::hasFullAccess(Auth::user());
        $wasCheckedIn = $reservation->status === HotelReservation::STATUS_CHECKED_IN;
        $wasCheckedOut = $reservation->status === HotelReservation::STATUS_CHECKED_OUT;

        if ($wasCheckedOut && ! $isAdmin) {
            return redirect()->back()->with('error', __('Checked-out reservations are locked. Only an administrator can make corrections.'));
        }

        $validator = \Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:191',
            'check_in_at' => 'required|date',
            'check_out_at' => 'required|date|after:check_in_at',
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'hotel_room_id' => 'nullable|exists:hotel_rooms,id',
            'status' => 'required|in:pending,confirmed,checked_in,checked_out,cancelled',
            'billing_mode' => 'required|in:nightly,monthly',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'discount_reason' => 'nullable|string|max:1000',
            'preferred_hotel_floor_id' => 'nullable|exists:hotel_floors,id',
            'estimated_arrival_time' => 'nullable|date_format:H:i',
            'id_type' => 'nullable|in:id,passport,letter',
            'passport_number' => 'nullable|string|max:100',
            'visa_number' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'meal_plan' => 'nullable|in:room_only,breakfast,half_board,full_board,all_inclusive',
            'booking_guarantee' => 'nullable|in:none,card,deposit,voucher',
            'payment_method' => 'nullable|string|max:50',
            'infants' => 'nullable|integer|min:0',
            'special_requests' => 'nullable|array',
            'special_requests.*' => 'string|max:64',
            'internal_notes' => 'nullable|string|max:5000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        if ($wasCheckedOut && $isAdmin) {
            $reservation->guest->update([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'id_type' => $request->id_type,
                'id_number' => $request->id_number,
                'passport_number' => $request->passport_number,
                'visa_number' => $request->visa_number,
                'nationality' => $request->nationality,
                'country' => $request->country,
            ]);
            $reservation->update([
                'internal_notes' => $request->internal_notes,
                'notes' => $request->notes,
            ]);

            return redirect()->route('hotel.reservations.index')->with('success', __('Administrator correction saved. Stay, rates and payments were not changed.'));
        }

        $creatorId = Auth::user()->creatorId();
        $checkInAt = Carbon::parse($request->check_in_at);
        $checkOutAt = Carbon::parse($request->check_out_at);

        $requestedStatus = (string) $request->status;
        $requestedRoomId = $request->filled('hotel_room_id') ? (int) $request->hotel_room_id : null;

        if ($wasCheckedIn) {
            // Booking channel / source stays locked after check-in.
            $request->merge(['source' => $reservation->source]);
            if (in_array($requestedStatus, [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED], true)) {
                return redirect()->back()->withInput()->with('error', __('Cannot move a checked-in reservation back to pending/confirmed. Use check-out or cancel via operations.'));
            }
            if ($requestedRoomId && $requestedRoomId !== (int) $reservation->hotel_room_id) {
                return redirect()->back()->withInput()->with('error', __('After check-in, change the room from Frontdesk → Change room.'));
            }
            $requestedRoomId = (int) $reservation->hotel_room_id;
        }

        if (! empty($requestedRoomId) && $this->roomHasConflict((int) $requestedRoomId, $checkInAt, $checkOutAt, (int) $reservation->id)) {
            return redirect()->back()->withInput()->with('error', __('Selected room is not available for the chosen check-in/check-out time.'));
        }

        if (! empty($requestedRoomId) && ! $wasCheckedIn) {
            $assignRoom = HotelRoom::where('created_by', $creatorId)->find((int) $requestedRoomId);
            if ($assignRoom) {
                $block = $this->roomStatusService->assignmentBlockReason(
                    $assignRoom,
                    (int) $reservation->hotel_room_id === (int) $assignRoom->id ? (int) $assignRoom->id : null
                );
                if ($block) {
                    return redirect()->back()->withInput()->with('error', $block);
                }
            }
        }

        $quote = $this->pricingService->quote(
            $creatorId,
            (int) $request->hotel_room_type_id,
            $requestedRoomId,
            $checkInAt,
            $checkOutAt,
            (string) $request->billing_mode
        );

        $discountAmount = round((float) ($request->discount_amount ?: 0), 2);
        if ($discountAmount > 0 && blank($request->discount_reason)) {
            return redirect()->back()->withInput()->with('error', __('Discount justification is required.'));
        }
        if (! $this->pricingService->validateDiscountAmount($discountAmount, (float) $quote['subtotal'])) {
            $maxPercent = rtrim(rtrim(number_format($this->pricingService->maxDiscountPercentForCurrentUser(), 2, '.', ''), '0'), '.');
            return redirect()->back()->withInput()->with('error', __('Discount exceeds your allowed limit of :percent%.', ['percent' => $maxPercent]));
        }

        $addonRows = $this->addonService->normalizeAddonInput($request->input('addons'));
        $occupancyError = $this->addonService->validateOccupancyForBooking(
            (int) $request->hotel_room_type_id,
            (int) ($request->adults ?: 1),
            (int) ($request->children ?: 0),
            $addonRows,
            $creatorId
        );
        if ($occupancyError) {
            return redirect()->back()->withInput()->with('error', $occupancyError);
        }

        $preferredFloor = null;
        if ($request->filled('preferred_hotel_floor_id')) {
            $preferredFloor = HotelFloor::where('created_by', $creatorId)->find((int) $request->preferred_hotel_floor_id);
        }

        $reservation->guest->update([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'birth_date' => $request->birth_date,
            'id_type' => $request->id_type,
            'id_number' => $request->id_number,
            'passport_number' => $request->passport_number,
            'visa_number' => $request->visa_number,
            'nationality' => $request->nationality,
            'country' => $request->country,
            'preferred_room_type_id' => $request->preferred_room_type_id ?: $request->hotel_room_type_id,
            'preferred_floor' => $preferredFloor?->name,
            'preferred_hotel_floor_id' => $preferredFloor?->id,
            'preferred_payment_method' => $request->preferred_payment_method,
            'credit_limit' => $request->credit_limit ?: 0,
            'notes' => $request->guest_notes,
            'allergies' => $request->allergies,
            'preferences' => $request->preferences,
            'drink_preferences' => $request->drink_preferences,
            'company_name' => $request->company_name,
            'is_vip' => $request->has('is_vip'),
        ]);

        $newStatus = $requestedStatus;
        if ($wasCheckedIn && $newStatus === HotelReservation::STATUS_CHECKED_IN) {
            $newStatus = HotelReservation::STATUS_CHECKED_IN;
        }

        $reservation->update([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'hotel_room_id' => $requestedRoomId,
            'hotel_rate_plan_id' => $quote['rate_plan_id'] ?? null,
            'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
            'estimated_arrival_time' => $request->estimated_arrival_time ?: null,
            'parking_requested' => $request->has('parking_requested'),
            'check_in_date' => $checkInAt->toDateString(),
            'check_out_date' => $checkOutAt->toDateString(),
            'adults' => (int) ($request->adults ?: 1),
            'children' => (int) ($request->children ?: 0),
            'infants' => (int) ($request->infants ?: 0),
            'source' => $wasCheckedIn ? $reservation->source : $request->source,
            'status' => $newStatus,
            'billing_mode' => $quote['billing_mode'],
            'meal_plan' => $request->meal_plan,
            'booking_guarantee' => $request->booking_guarantee,
            'payment_method' => $request->payment_method,
            'rate_per_night' => $quote['rate_amount'],
            'discount_amount' => $discountAmount,
            'discount_percent' => $request->filled('discount_percent') ? (float) $request->discount_percent : null,
            'discount_reason' => $discountAmount > 0 ? $request->discount_reason : null,
            'notes' => $request->notes,
            'special_requests' => array_values(array_filter((array) $request->input('special_requests', []))),
            'internal_notes' => $request->internal_notes,
        ]);

        $this->addonService->syncReservationAddons($reservation, $addonRows, $creatorId);

        if ($reservation->status === HotelReservation::STATUS_CHECKED_IN) {
            try {
                $this->checkInService->ensureStayAndFolio($reservation->fresh(), (int) $creatorId, false);
            } catch (\InvalidArgumentException $e) {
                return redirect()->back()->withInput()->with('error', $e->getMessage());
            }
            $reservation->load('stay.folio');
            if ($reservation->stay?->folio) {
                $this->addonService->postReservationAddonsToFolio(
                    $reservation,
                    $reservation->stay->folio,
                    $creatorId
                );
            }
        }

        return redirect()->route('hotel.reservations.index')->with('success', __('Reservation updated successfully.'));
    }

    public function destroy(HotelReservation $reservation)
    {
        if ((! $this->canManage()) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (in_array($reservation->status, [HotelReservation::STATUS_CHECKED_IN, HotelReservation::STATUS_CHECKED_OUT], true)) {
            return redirect()->back()->with('error', __('Checked-in or checked-out reservations cannot be deleted.'));
        }

        $reservation->delete();

        return redirect()->route('hotel.reservations.index')->with('success', __('Reservation deleted successfully.'));
    }

    public function guestSearch(Request $request)
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $q = trim((string) $request->get('q', ''));
        if ($q === '' || strlen($q) < 2) {
            return response()->json([]);
        }

        $guests = HotelGuest::where('created_by', Auth::user()->creatorId())
            ->where(function ($w) use ($q) {
                $w->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('id_number', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            })
            ->orderBy('first_name')
            ->limit(15)
            ->get([
                'id', 'first_name', 'last_name', 'email', 'phone', 'birth_date', 'id_type', 'id_number',
                'passport_number', 'visa_number', 'nationality', 'country', 'company_name', 'is_vip',
                'preferred_room_type_id', 'preferred_floor',
                'preferred_hotel_floor_id', 'preferred_payment_method', 'credit_limit', 'notes',
                'allergies', 'preferences', 'drink_preferences',
            ]);

        return response()->json($guests);
    }

    public function rateQuote(Request $request)
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $checkInAt = $request->filled('check_in_at') ? Carbon::parse($request->get('check_in_at')) : null;
        $checkOutAt = $request->filled('check_out_at') ? Carbon::parse($request->get('check_out_at')) : null;

        return response()->json($this->pricingService->quote(
            Auth::user()->creatorId(),
            (int) $request->get('hotel_room_type_id'),
            $request->filled('hotel_room_id') ? (int) $request->get('hotel_room_id') : null,
            $checkInAt,
            $checkOutAt,
            (string) $request->get('billing_mode', 'nightly')
        ));
    }

    public function suggestRoom(Request $request)
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $creatorId = Auth::user()->creatorId();
        $roomTypeId = (int) $request->get('hotel_room_type_id');
        if (! $roomTypeId || ! $request->filled('check_in_at') || ! $request->filled('check_out_at')) {
            return response()->json(['room_id' => null]);
        }

        $checkInAt = Carbon::parse($request->get('check_in_at'));
        $checkOutAt = Carbon::parse($request->get('check_out_at'));
        $rooms = HotelRoom::where('created_by', $creatorId)
            ->where('hotel_room_type_id', $roomTypeId)
            ->where('status', HotelRoom::STATUS_AVAILABLE)
            ->orderBy('room_no')
            ->get(['id']);

        foreach ($rooms as $room) {
            if (! $this->roomHasConflict((int) $room->id, $checkInAt, $checkOutAt)) {
                return response()->json(['room_id' => $room->id]);
            }
        }

        return response()->json(['room_id' => null]);
    }

    public function calendarUpdate(Request $request, HotelReservation $reservation)
    {
        if ((! $this->canManage()) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        if (in_array($reservation->status, [HotelReservation::STATUS_CHECKED_IN, HotelReservation::STATUS_CHECKED_OUT], true)) {
            return response()->json(['error' => __('Cannot move checked-in/out reservation from calendar.')], 422);
        }

        $request->validate([
            'check_in_at' => 'required|date',
            'check_out_at' => 'required|date|after:check_in_at',
            'hotel_room_id' => 'nullable|exists:hotel_rooms,id',
        ]);

        $checkInAt = Carbon::parse($request->check_in_at);
        $checkOutAt = Carbon::parse($request->check_out_at);
        $roomId = $request->filled('hotel_room_id') ? (int) $request->hotel_room_id : $reservation->hotel_room_id;
        if ($roomId && $this->roomHasConflict($roomId, $checkInAt, $checkOutAt, (int) $reservation->id)) {
            return response()->json(['error' => __('Room conflict for new dates.')], 422);
        }

        if ($roomId) {
            $assignRoom = HotelRoom::where('created_by', Auth::user()->creatorId())->find($roomId);
            if ($assignRoom) {
                $block = $this->roomStatusService->assignmentBlockReason(
                    $assignRoom,
                    (int) $reservation->hotel_room_id === $roomId ? $roomId : null
                );
                if ($block) {
                    return response()->json(['error' => $block], 422);
                }
            }
        }

        $quote = $this->pricingService->quote(
            Auth::user()->creatorId(),
            (int) $reservation->hotel_room_type_id,
            $roomId,
            $checkInAt,
            $checkOutAt,
            (string) ($reservation->billing_mode ?? 'nightly')
        );

        $reservation->update([
            'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
            'check_in_date' => $checkInAt->toDateString(),
            'check_out_date' => $checkOutAt->toDateString(),
            'hotel_room_id' => $roomId ?: $reservation->hotel_room_id,
            'hotel_rate_plan_id' => $quote['rate_plan_id'] ?? null,
            'rate_per_night' => $quote['rate_amount'],
        ]);

        return response()->json(['success' => true]);
    }

    protected function sendReservationEmail(HotelReservation $reservation): void
    {
        $guest = $reservation->guest;
        if (! $guest || empty($guest->email)) {
            return;
        }

        try {
            $rateDisplay = Auth::user()->priceFormat($reservation->rate_per_night) . ' / ' . __($reservation->billing_mode === 'monthly' ? 'month' : 'night');
            LoggedMail::send($guest->email, new HotelReservationCreatedMail($reservation, [
                'billing_mode' => $reservation->billing_mode,
                'rate_display' => $rateDisplay,
            ]), [
                'source' => 'hotel_reservation',
                'subject' => __('Hotel reservation confirmation'),
            ]);

            $reservation->forceFill(['guest_email_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
