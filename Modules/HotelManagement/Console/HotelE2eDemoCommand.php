<?php

namespace Modules\HotelManagement\Console;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Http\Controllers\HotelFrontdeskController;
use Modules\HotelManagement\Services\HotelNightAuditPreviewService;

class HotelE2eDemoCommand extends Command
{
    protected $signature = 'hotelmanagement:e2e-demo
        {--user= : Company owner user id (default: first company user)}
        {--checkout : Complete checkout on RSV-DEMO-FLOW if in-house}
        {--audit-date= : Run night-audit preview for date (default: yesterday)}';

    protected $description = 'Verify (or run) the hotel end-to-end demo flow: reservation → check-in → services → pay → checkout → night audit preview';

    public function handle(
        HotelNightAuditPreviewService $previewService,
    ): int {
        $userId = (int) ($this->option('user') ?: User::where('type', 'company')->orderBy('id')->value('id'));
        if ($userId <= 0) {
            $this->error('No company user found. Create a company user or pass --user=<id>.');

            return self::FAILURE;
        }

        $user = User::find($userId);
        if (! $user) {
            $this->error("User {$userId} not found.");

            return self::FAILURE;
        }

        Auth::login($user);
        $creatorId = (int) $user->creatorId();

        $this->info("Hotel E2E demo — creator_id={$creatorId}");
        $steps = [];

        $steps[] = $this->checkStep(
            'Room inventory',
            HotelRoom::where('created_by', $creatorId)->exists(),
            'Run HotelDummyDataSeeder'
        );

        $reservation = HotelReservation::where('created_by', $creatorId)
            ->where('reservation_no', 'RSV-DEMO-FLOW')
            ->with(['stay.folio.lines', 'stay.folio.payments', 'guest', 'room'])
            ->first();

        $steps[] = $this->checkStep(
            'Demo reservation RSV-DEMO-FLOW',
            (bool) $reservation,
            'Re-run HotelDummyDataSeeder'
        );

        if ($reservation) {
            $stay = $reservation->stay;
            $folio = $stay?->folio;

            $steps[] = $this->checkStep(
                'Checked-in stay',
                $reservation->status === HotelReservation::STATUS_CHECKED_IN && $stay?->status === 'in_house',
                'Expected in-house demo guest'
            );

            $steps[] = $this->checkStep(
                'Open folio with lines',
                $folio && $folio->lines->isNotEmpty(),
                'Folio should have room + service lines'
            );

            $hasAddon = $folio && $folio->lines->contains(fn ($l) => $l->line_type === 'addon_charge');
            $hasMinibar = $folio && $folio->lines->contains(fn ($l) => in_array($l->line_type, ['misc_charge', 'minibar_charge', 'pos_charge'], true));

            $steps[] = $this->checkStep('Addon charge on folio', $hasAddon, 'Extra bed addon line');
            $steps[] = $this->checkStep('Minibar / extra on folio', $hasMinibar, 'Minibar consumption line');

            if ($reservation->hotel_room_id) {
                $room = HotelRoom::find($reservation->hotel_room_id);
                $steps[] = $this->checkStep(
                    'Room occupied',
                    $room && $room->status === HotelRoom::STATUS_OCCUPIED,
                    "Room {$room?->room_no} should be occupied"
                );
            }

            if ($this->option('checkout') && $stay && $folio && $stay->status === 'in_house') {
                $balance = max(0, (float) $folio->grand_total - (float) $folio->paid_total);
                if ($balance > 0.01) {
                    app(\Modules\HotelManagement\Services\HotelFolioPaymentService::class)->recordPayment(
                        $folio,
                        $balance,
                        'cash',
                        $creatorId,
                        'E2E-DEMO',
                        'E2E demo settlement'
                    );
                    $folio->refresh();
                    $this->line('  → Recorded final payment: ' . number_format($balance, 2));
                }

                if (Schema::hasColumn('hotel_stays', 'minibar_verified_at') && ! $stay->minibar_verified_at) {
                    $stay->update(['minibar_verified_at' => now()]);
                }

                $request = Request::create('/', 'POST', [
                    'payment_amount' => 0,
                    'payment_method' => 'cash',
                ]);

                app(HotelFrontdeskController::class)->checkOut($request, $stay->fresh());
                $reservation->refresh();
                $folio->refresh();

                $steps[] = $this->checkStep(
                    'Checkout completed',
                    $reservation->status === HotelReservation::STATUS_CHECKED_OUT,
                    'Reservation should be checked out'
                );

                $steps[] = $this->checkStep(
                    'Folio settled',
                    $folio->paymentStatusLabel() === 'paid',
                    'Folio paid after checkout'
                );

                if ($reservation->hotel_room_id) {
                    $room = HotelRoom::find($reservation->hotel_room_id);
                    $steps[] = $this->checkStep(
                        'Room dirty after checkout',
                        $room && $room->status === HotelRoom::STATUS_DIRTY,
                        'Room status should be dirty'
                    );
                }

                if (Schema::hasColumn('hotel_folios', 'invoice_id') && $folio->invoice_id) {
                    $steps[] = $this->checkStep(
                        'ERP invoice linked',
                        true,
                        'invoice_id=' . $folio->invoice_id
                    );
                }
            }
        }

        $auditDate = $this->option('audit-date') ?: Carbon::yesterday()->toDateString();
        $preview = $previewService->build($creatorId, $auditDate, $user->activeBranchContextId());
        $steps[] = $this->checkStep(
            'Night audit preview',
            isset($preview['revenue'], $preview['occupancy']),
            "Revenue total: " . ($preview['revenue']['total'] ?? 0) . " for {$auditDate}"
        );

        $failed = collect($steps)->where('ok', false)->count();
        $this->newLine();
        $this->table(['Step', 'Status', 'Note'], collect($steps)->map(fn ($s) => [
            $s['label'],
            $s['ok'] ? 'OK' : 'FAIL',
            $s['note'],
        ])->all());

        if ($failed > 0) {
            $this->error("{$failed} step(s) failed. Run: php artisan db:seed --class=\"Modules\\HotelManagement\\Database\\Seeders\\HotelDummyDataSeeder\"");

            return self::FAILURE;
        }

        $this->info('All E2E demo checks passed.');

        return self::SUCCESS;
    }

    /**
     * @return array{label: string, ok: bool, note: string}
     */
    protected function checkStep(string $label, bool $ok, string $note): array
    {
        $this->line(($ok ? '<fg=green>✓</>' : '<fg=red>✗</>') . " {$label}");

        return ['label' => $label, 'ok' => $ok, 'note' => $note];
    }
}
