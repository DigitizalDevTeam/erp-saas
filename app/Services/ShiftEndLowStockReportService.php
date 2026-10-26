<?php

namespace App\Services;

use App\Mail\WarehouseLowStockReportMail;
use App\Models\PosShift;
use App\Models\Utility;
use App\Support\LoggedMail;
use Illuminate\Support\Facades\Log;

/**
 * Shift-end low-stock email report (per warehouse, min only).
 *
 * Decided behaviour: one report per shift end, listing the items that are
 * currently at or below their Low level in each warehouse. If an item is
 * still low at the next shift end, it appears again. Sending never breaks
 * the shift close: every failure is caught and logged.
 */
class ShiftEndLowStockReportService
{
    /**
     * Send the report for a just-closed shift.
     * Returns the number of low-stock rows emailed, or 0 when skipped.
     */
    public static function sendForShift(PosShift $shift): int
    {
        try {
            $companyId = (int) ($shift->created_by ?? 0);
            if ($companyId <= 0) {
                return 0;
            }

            $settings = Utility::settingsById($companyId);
            $recipients = array_values(array_filter(array_map(
                'trim',
                preg_split('/[,\s;]+/', (string) ($settings['low_stock_warehouse_report_emails'] ?? ''))
            )));
            if (count($recipients) === 0) {
                return 0;
            }

            // Same rows as the shift report's Minimum Stock Alert section:
            // per-warehouse Low wins, then product Low, then the POS default.
            // When the shift was closed with a warehouse selection, the
            // email follows it; otherwise it keeps the old all-warehouses
            // behaviour.
            $selection = $shift->report_warehouse_ids;
            $result = app(PosShiftReportService::class)->minimumStockAlertRows($companyId, null, is_array($selection) ? $selection : null);
            $rows = $result['rows'] ?? [];
            if (count($rows) === 0) {
                return 0;
            }

            $globalThreshold = $result['globalThreshold'] ?? 10;
            $minRows = [];
            foreach ($rows as $row) {
                $minRows[] = [
                    'name' => (string) ($row->product_name ?? ''),
                    'warehouse' => (string) ($row->warehouse_name ?? ''),
                    'sku' => (string) ($row->sku ?: '—'),
                    'qty' => (float) ($row->warehouse_quantity ?? 0),
                    'min' => $row->low_stock_threshold !== null ? (int) $row->low_stock_threshold : (int) $globalThreshold,
                ];
            }

            LoggedMail::send($recipients, new WarehouseLowStockReportMail(['min' => $minRows], $settings), [
                'source' => 'warehouse_low_stock_report',
                'subject' => __('Low Stock Report by Warehouse'),
                'created_by' => $companyId,
            ]);

            return count($minRows);
        } catch (\Throwable $e) {
            Log::warning('Shift-end low-stock report failed: ' . $e->getMessage());

            return 0;
        }
    }
}
