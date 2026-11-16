<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client issues — Issue 5: multi-document KYC (company vs contact person).
 *
 * - Adds a nullable `contact_kyc` JSON (text) column for the contact
 *   person's KYC documents.
 * - Converts the legacy single-document `kyc` object form
 *   {"document_type","document_number"} (+ `kyc_document_path`) into the
 *   new array form [{"document_type","document_number","file_path"}].
 *   Records with no type, no number and no file become an empty array.
 *   Already-converted rows are skipped, so the migration is idempotent.
 *
 * Note: the `kyc` / `kyc_document_path` columns come from the installer
 * SQL, not from any migration — on a migration-built schema they may
 * not exist yet. They are added here (guarded) so the feature works
 * there too, and the conversion only runs when `kyc` exists.
 */
class CustomerKycDocuments extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customers', 'kyc')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->text('kyc')->nullable();
            });
        }

        if (!Schema::hasColumn('customers', 'kyc_document_path')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('kyc_document_path')->nullable();
            });
        }

        if (!Schema::hasColumn('customers', 'contact_kyc')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->text('contact_kyc')->nullable();
            });
        }

        if (!Schema::hasColumn('customers', 'kyc')) {
            return;
        }

        DB::table('customers')->whereNotNull('kyc')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $decoded = json_decode($row->kyc, true);
                if (!is_array($decoded)) {
                    continue;
                }
                // Array (list) form is already converted; the legacy
                // object form carries the document_type/document_number keys.
                if (!array_key_exists('document_type', $decoded) && !array_key_exists('document_number', $decoded)) {
                    continue;
                }

                $doc = [
                    'document_type' => $decoded['document_type'] ?? null,
                    'document_number' => $decoded['document_number'] ?? null,
                    'file_path' => $row->kyc_document_path ?? null,
                ];
                $docs = [];
                if (!empty($doc['document_type']) || !empty($doc['document_number']) || !empty($doc['file_path'])) {
                    $docs[] = $doc;
                }

                DB::table('customers')->where('id', $row->id)->update(['kyc' => json_encode($docs)]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'contact_kyc')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('contact_kyc');
            });
        }

        if (!Schema::hasColumn('customers', 'kyc')) {
            return;
        }

        // Best-effort revert: single-element arrays go back to object
        // form. Multi-element arrays are left as arrays — readers
        // handle both forms, so nothing breaks.
        DB::table('customers')->whereNotNull('kyc')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $decoded = json_decode($row->kyc, true);
                if (!is_array($decoded) || empty($decoded)) {
                    continue;
                }
                if (array_key_exists('document_type', $decoded)) {
                    continue;
                }
                if (count($decoded) !== 1 || !is_array($decoded[0])) {
                    continue;
                }
                $doc = $decoded[0];
                DB::table('customers')->where('id', $row->id)->update([
                    'kyc' => json_encode([
                        'document_type' => $doc['document_type'] ?? null,
                        'document_number' => $doc['document_number'] ?? null,
                    ]),
                ]);
            }
        });
    }
}
