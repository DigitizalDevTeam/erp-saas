<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One imported historical monthly line value for the management reports
 * (P&L, %P&L).
 *
 * Line keys:
 *  revenue_ex, revenue_iva — gross revenue ex-VAT and its IVA
 *  cogs, variables, fixed, other, depreciation, amortization
 *  personnel:{department name} — cost split; the name
 *    'Impostos e Outros Custos' is the employer-INSS sub-row
 *  channel:{channel name}:ex / channel:{channel name}:iva — sales split
 *    (the sheet's channels: Software Faturação, Restaurante, Bar, Eventos)
 *
 * A row means "this month's value for this line comes from the imported
 * file, not from the system". Deleting the row reverts the line to the
 * automatic system value.
 *
 * Company scoping follows this app's convention: `created_by` holds
 * the creator/company user id (see User::creatorId()).
 */
class ManagementReportHistory extends Model
{
    /** Laravel pluralizes "history" to "histories" — pin the table name. */
    protected $table = 'management_report_history';

    protected $fillable = [
        'created_by', 'year', 'month', 'line', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /** Line keys that are plain monthly amounts (no name embedded). */
    public const SIMPLE_LINES = [
        'revenue_ex', 'revenue_iva', 'cogs', 'variables',
        'fixed', 'other', 'depreciation', 'amortization',
    ];

    /** Sheet channel names, in the order the client file lists them. */
    public const SHEET_CHANNELS = ['Software Faturação', 'Restaurante', 'Bar', 'Eventos'];

    /** Sheet personnel split names, in the order the client file lists them. */
    public const SHEET_PERSONNEL = ['Operação (CC 101)', 'Group (CC 102)', 'Impostos e Outros Custos'];

    /** The personnel name that carries the employer-INSS sub-row. */
    public const INSS_NAME = 'Impostos e Outros Custos';

    /** Is this line key valid (simple, personnel:{name}, channel:{name}:ex|iva)? */
    public static function isValidLine(string $line): bool
    {
        if (in_array($line, self::SIMPLE_LINES, true)) {
            return true;
        }
        if (str_starts_with($line, 'personnel:')) {
            $name = trim(substr($line, strlen('personnel:')));
            return $name !== '' && mb_strlen($name) <= 100;
        }
        if (str_starts_with($line, 'channel:') && (str_ends_with($line, ':ex') || str_ends_with($line, ':iva'))) {
            $name = substr($line, strlen('channel:'), -strlen(':ex'));
            $name = trim($name);
            return $name !== '' && mb_strlen($name) <= 60;
        }

        return false;
    }
}
