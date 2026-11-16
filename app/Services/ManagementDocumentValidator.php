<?php

namespace App\Services;

use App\Models\ManagementDocument;
use App\Models\ManagementSupplierCategory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One validation for Management Documents, shared by the form
 * (ManagementDocumentController) and the import
 * (ManagementDocumentImport) so a row can never pass in one place
 * and fail in the other.
 *
 * Rules (from the client's file + the build):
 *  - doc_number is required for EVERY type (there is no 'manual'
 *    type any more — a cost with no document is a journal, not a
 *    document row).
 *  - A credit note must be negative (base / IVA / retention too);
 *    every other costing document must be zero or positive.
 *  - TOTAL must equal BASE + IVA − retention when BASE is present.
 *  - The category is required — or the supplier's default category
 *    auto-assigns (the client: assign a category to a supplier,
 *    all that supplier's documents get that category).
 *  - DUPLICATE: the same supplier (case-insensitive, trimmed) +
 *    doc number + doc date + total as an existing costing document
 *    is rejected ("This document is already entered") — entering
 *    the same document twice would double the cost.
 */
class ManagementDocumentValidator
{
    /**
     * Validate one document's fields. Returns the validated data
     * (with the category auto-assigned from the supplier default
     * when the user left it empty).
     *
     * @throws ValidationException
     */
    public static function validate(array $input, int $creatorId, ?int $ignoreId = null): array
    {
        $validator = Validator::make($input, [
            'doc_date' => ['required', 'date'],
            'supplier_nif' => ['nullable', 'string', 'max:50'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'doc_number' => ['required', 'string', 'max:100'],
            'doc_type' => ['required', 'in:' . implode(',', ManagementDocument::DOC_TYPES)],
            'base' => ['nullable', 'numeric'],
            'iva' => ['nullable', 'numeric'],
            'retention' => ['nullable', 'numeric'],
            'total' => ['required', 'numeric'],
            'status' => ['required', 'in:' . implode(',', ManagementDocument::STATUSES)],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'management_rule_id' => [
                'nullable', 'integer',
                Rule::exists('management_rules', 'id')->where('created_by', $creatorId),
            ],
            'management_cost_centre_id' => [
                'nullable', 'integer',
                Rule::exists('management_cost_centres', 'id')->where('created_by', $creatorId),
            ],
            'notes' => ['nullable', 'string'],
        ]);

        // One cross-field rule (the per-rule `where()` takes only
        // (column, value) — a third argument is silently dropped, so
        // the "not equal" form is never used here): the rule must
        // belong to this company, enforced by where('created_by', $creatorId).
        $validator->after(function ($validator) use ($input, $creatorId, $ignoreId) {
            $data = $validator->getData();
            $type = $data['doc_type'] ?? null;
            $total = $data['total'] ?? null;

            if ($type === 'credit_note' && $total !== null && $total >= 0) {
                $validator->errors()->add('total', __('A credit note must have a negative amount.'));
            }
            if (in_array($type, ['invoice', 'bank_transfer'], true) && $total !== null && $total < 0) {
                $validator->errors()->add('total', __('The amount must be zero or positive.'));
            }
            // A credit note's amounts are negative (base −100, total −100);
            // every other type's base / VAT / retention must be >= 0.
            foreach (['base', 'iva', 'retention'] as $field) {
                $value = $data[$field] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if ($type === 'credit_note' && (float) $value > 0) {
                    $validator->errors()->add($field, __('A credit note must have a negative amount.'));
                }
                if ($type !== 'credit_note' && (float) $value < 0) {
                    $validator->errors()->add($field, __('The amount must be zero or positive.'));
                }
            }
            if (isset($data['base']) && $data['base'] !== '' && $data['base'] !== null && $total !== null) {
                $expected = (float) $data['base'] + (float) ($data['iva'] ?? 0) - (float) ($data['retention'] ?? 0);
                if (abs((float) $total - $expected) > 0.01) {
                    $validator->errors()->add('total', __('The total must equal base + VAT − retention.'));
                }
            }

            // Duplicate: same supplier + doc number + date + total as
            // an existing costing document (quotations are not costs,
            // so they are never blocked — and never block).
            if (in_array($type, ManagementDocument::COSTING_TYPES, true)
                && ! empty($data['doc_number'])
                && ! empty($data['doc_date'])
                && $total !== null
                && ! $validator->errors()->has('doc_number')
                && ! $validator->errors()->has('doc_date')
                && ! $validator->errors()->has('total')
            ) {
                $exists = ManagementDocument::where('created_by', $creatorId)
                    ->whereIn('doc_type', ManagementDocument::COSTING_TYPES)
                    ->whereRaw('COALESCE(LOWER(TRIM(supplier_name)), \'\') = ?', [mb_strtolower(trim((string) ($data['supplier_name'] ?? '')))])
                    ->whereRaw('COALESCE(LOWER(TRIM(doc_number)), \'\') = ?', [mb_strtolower(trim((string) $data['doc_number']))])
                    ->whereDate('doc_date', $data['doc_date'])
                    ->where('total', round((float) $total, 2))
                    ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                    ->exists();
                if ($exists) {
                    $validator->errors()->add('doc_number', __('This document is already entered.'));
                }
            }
        });

        $data = $validator->validate();

        // Auto-assign the category from the supplier's default when the
        // user left it empty (the client: assign a category to a
        // supplier, all that supplier's documents get that category).
        if (empty($data['management_rule_id'])) {
            $default = ManagementSupplierCategory::defaultRuleFor(
                $creatorId,
                $data['supplier_name'] ?? null
            );
            if ($default) {
                $data['management_rule_id'] = $default->id;
            } else {
                throw ValidationException::withMessages([
                    'management_rule_id' => __('The category is required.'),
                ]);
            }
        }

        return $data;
    }
}
