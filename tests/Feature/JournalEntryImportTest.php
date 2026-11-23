<?php

namespace Tests\Feature;

use App\Imports\JournalEntryImport;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Journal Entry XLSX import (2026-11-18) — Import button on the
 * Journal Entry page, XLSX only. One row per journal line; rows
 * sharing a reference become one PENDING entry (manual source),
 * exactly like the manual form. Unbalanced groups, unknown
 * accounts and missing references are skipped and reported.
 */
class JournalEntryImportTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->createTables();
        $this->seedPermissions();
        $this->seedBaseRows();
        $this->user = $this->makeCompanyUser();
        $this->user->givePermissionTo(['create journal entry', 'manage journal entry']);
        $this->actingAs($this->user);

        foreach (['611' => 'Inventory Cost', '111' => 'Cash', '411' => 'Sales'] as $code => $name) {
            DB::table('chart_of_accounts')->insert([
                'name' => $name, 'code' => (int) $code, 'type' => 0, 'sub_type' => 0,
                'is_enabled' => 1, 'created_by' => $this->user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['journal_items', 'journal_entries', 'chart_of_accounts', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'settings', 'languages', 'email_templates', 'ch_messages', 'plans', 'branches', 'branch_user'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_index_page_shows_import_button(): void
    {
        $response = $this->get(route('journal-entry.index'));
        $response->assertOk();
        $response->assertSee('Import', false);
        $response->assertSee(route('journal-entry.import.form'), false);
    }

    public function test_import_form_and_template_are_reachable(): void
    {
        $response = $this->get(route('journal-entry.import.form'));
        $response->assertOk();
        $response->assertSee('Select XLSX File', false);
        $response->assertSee(route('journal-entry.import.template'), false);

        $response = $this->get(route('journal-entry.import.template'));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
    }

    public function test_valid_file_creates_pending_entries(): void
    {
        $file = $this->makeXlsx([
            ['2026-11-18', 'JE-001', 'Stock purchase', '611', 500, '', 'Inventory cost'],
            ['2026-11-18', 'JE-001', 'Stock purchase', '111', '', 500, 'Cash payment'],
            ['2026-11-19', 'JE-002', 'Sale', '111', 200, '', 'Cash received'],
            ['2026-11-19', 'JE-002', 'Sale', '411', '', 200, 'Sales revenue'],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertRedirect(route('journal-entry.index'));
        $response->assertSessionHas('success', 'Imported 2 journal entries. Skipped 0 rows.');
        $this->assertSame(2, JournalEntry::count());

        $first = JournalEntry::where('reference', 'JE-001')->first();
        $this->assertNotNull($first);
        $this->assertSame(JournalEntry::STATUS_PENDING, (int) $first->status);
        $this->assertSame('manual', $first->source);
        $this->assertSame('2026-11-18', $first->date);
        $this->assertSame(11, (int) $first->accounting_period);
        $this->assertSame(2, $first->accounts()->count());
        $this->assertEquals(500, $first->totalDebit());
        $this->assertEquals(500, $first->totalCredit());

        $second = JournalEntry::where('reference', 'JE-002')->first();
        $this->assertNotNull($second);
        $this->assertSame(1, (int) $second->journal_id - (int) $first->journal_id);
    }

    public function test_unbalanced_group_is_skipped_and_valid_group_still_imports(): void
    {
        $file = $this->makeXlsx([
            ['2026-11-18', 'JE-BAD', 'Does not balance', '611', 500, '', ''],
            ['2026-11-18', 'JE-BAD', 'Does not balance', '111', '', 400, ''],
            ['2026-11-18', 'JE-OK', 'Balanced', '611', 100, '', ''],
            ['2026-11-18', 'JE-OK', 'Balanced', '111', '', 100, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertRedirect(route('journal-entry.index'));
        $response->assertSessionHas('success', 'Imported 1 journal entries. Skipped 2 rows.');
        $response->assertSessionHas('import_errors');
        $this->assertSame(1, JournalEntry::count());
        $this->assertNull(JournalEntry::where('reference', 'JE-BAD')->first());
        $this->assertNotNull(JournalEntry::where('reference', 'JE-OK')->first());
        $errors = $response->getSession()->get('import_errors');
        $this->assertStringContainsString('Debit and Credit must be Equal', $errors[0]);
    }

    public function test_unknown_account_and_missing_reference_are_skipped(): void
    {
        $file = $this->makeXlsx([
            ['2026-11-18', 'JE-NOACC', 'Unknown account', '999', 100, '', ''],
            ['2026-11-18', 'JE-NOACC', 'Unknown account', '111', '', 100, ''],
            ['2026-11-18', '', 'No reference', '611', 100, '', ''],
            ['2026-11-18', '', 'No reference', '111', '', 100, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertRedirect(route('journal-entry.index'));
        $response->assertSessionHas('success', 'Imported 0 journal entries. Skipped 4 rows.');
        $this->assertSame(0, JournalEntry::count());
        $errors = $response->getSession()->get('import_errors');
        $this->assertStringContainsString('Reference is required', $errors[0]);
        $this->assertStringContainsString('Account code 999 was not found', $errors[2]);
    }

    public function test_non_xlsx_file_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('import.csv', 100, 'text/csv');

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('error', 'Only XLSX files are supported.');
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_real_csv_txt_and_pdf_files_are_rejected_with_visible_error(): void
    {
        $csv = "date,reference,description,account_code,debit,credit,line_description\n2026-11-18,JE-001,Test,611,500,,x\n2026-11-18,JE-001,Test,111,,500,x\n";
        foreach ([
            $this->makeRealFile('import.csv', $csv, 'text/csv'),
            $this->makeRealFile('import.txt', 'just some text', 'text/plain'),
            $this->makeRealFile('import.pdf', "%PDF-1.4\n%fake pdf\n", 'application/pdf'),
        ] as $file) {
            $response = $this->post(route('journal-entry.import'), ['file' => $file]);

            $response->assertSessionHas('error', 'Only XLSX files are supported.');
            $this->assertSame(0, JournalEntry::count());
        }
    }

    public function test_xlsx_content_with_csv_extension_is_rejected(): void
    {
        $xlsx = $this->makeXlsx([
            ['2026-11-18', 'JE-001', 'Test', '611', 500, '', ''],
            ['2026-11-18', 'JE-001', 'Test', '111', '', 500, ''],
        ]);
        $renamed = new UploadedFile($xlsx->getRealPath(), 'import.csv', 'text/csv', null, true);

        $response = $this->post(route('journal-entry.import'), ['file' => $renamed]);

        $response->assertSessionHas('error', 'Only XLSX files are supported.');
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_csv_content_with_xlsx_extension_is_rejected(): void
    {
        $csv = "date,reference,description,account_code,debit,credit,line_description\n2026-11-18,JE-001,Test,611,500,,x\n2026-11-18,JE-001,Test,111,,500,x\n";
        $file = $this->makeRealFile('import.xlsx', $csv, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('error', 'Only XLSX files are supported.');
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_user_without_create_permission_is_denied(): void
    {
        $this->user->revokePermissionTo('create journal entry');

        $response = $this->post(route('journal-entry.import'), ['file' => $this->makeXlsx([])]);

        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_supplied_journal_number_is_used_and_auto_numbering_continues_after_it(): void
    {
        $file = $this->makeXlsxFull([
            [42, '2026-11-18', '', '', '', 'JE-NUM', 'Numbered', '611', 500, '', 'Inventory cost'],
            [42, '2026-11-18', '', '', '', 'JE-NUM', 'Numbered', '111', '', 500, 'Cash payment'],
            ['', '2026-11-19', '', '', '', 'JE-AUTO', 'Auto', '611', 100, '', ''],
            ['', '2026-11-19', '', '', '', 'JE-AUTO', 'Auto', '111', '', 100, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertRedirect(route('journal-entry.index'));
        $response->assertSessionHas('success', 'Imported 2 journal entries. Skipped 0 rows.');
        $this->assertSame(42, (int) JournalEntry::where('reference', 'JE-NUM')->first()->journal_id);
        $this->assertSame(43, (int) JournalEntry::where('reference', 'JE-AUTO')->first()->journal_id);
    }

    public function test_formatted_journal_number_is_accepted(): void
    {
        $file = $this->makeXlsxFull([
            ['JE-00007', '2026-11-18', '', '', '', 'JE-FMT', 'Formatted', '611', 500, '', ''],
            ['JE-00007', '2026-11-18', '', '', '', 'JE-FMT', 'Formatted', '111', '', 500, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('success', 'Imported 1 journal entries. Skipped 0 rows.');
        $this->assertSame(7, (int) JournalEntry::where('reference', 'JE-FMT')->first()->journal_id);
    }

    public function test_duplicate_journal_number_is_skipped_and_other_group_still_imports(): void
    {
        $file = $this->makeXlsxFull([
            [9, '2026-11-18', '', '', '', 'JE-DUP1', 'First', '611', 500, '', ''],
            [9, '2026-11-18', '', '', '', 'JE-DUP1', 'First', '111', '', 500, ''],
            [9, '2026-11-18', '', '', '', 'JE-DUP2', 'Second', '611', 100, '', ''],
            [9, '2026-11-18', '', '', '', 'JE-DUP2', 'Second', '111', '', 100, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertRedirect(route('journal-entry.index'));
        $response->assertSessionHas('success', 'Imported 1 journal entries. Skipped 2 rows.');
        $response->assertSessionHas('import_errors');
        $this->assertSame(1, JournalEntry::count());
        $this->assertNotNull(JournalEntry::where('reference', 'JE-DUP1')->first());
        $this->assertNull(JournalEntry::where('reference', 'JE-DUP2')->first());
        $errors = $response->getSession()->get('import_errors');
        $this->assertStringContainsString('already used', $errors[0]);
    }

    public function test_mismatched_journal_number_in_group_is_skipped(): void
    {
        $file = $this->makeXlsxFull([
            [10, '2026-11-18', '', '', '', 'JE-MIX', 'Mixed', '611', 500, '', ''],
            [11, '2026-11-18', '', '', '', 'JE-MIX', 'Mixed', '111', '', 500, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('success', 'Imported 0 journal entries. Skipped 2 rows.');
        $this->assertSame(0, JournalEntry::count());
        $errors = $response->getSession()->get('import_errors');
        $this->assertStringContainsString('same journal number', $errors[0]);
    }

    public function test_zero_journal_number_is_rejected(): void
    {
        // Built cell-by-cell: fromArray() drops 0 values, so a real
        // file with a 0 typed in needs setCellValue to reproduce it.
        $file = $this->makeXlsxFullCells([
            [0, '2026-11-18', '', '', '', 'JE-ZERO', 'Zero', '611', 500, '', ''],
            [0, '2026-11-18', '', '', '', 'JE-ZERO', 'Zero', '111', '', 500, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('success', 'Imported 0 journal entries. Skipped 2 rows.');
        $this->assertSame(0, JournalEntry::count());
        $errors = $response->getSession()->get('import_errors');
        $this->assertStringContainsString('not valid', $errors[0]);
    }

    public function test_opening_balances_month_zero_is_stored(): void
    {
        $file = $this->makeXlsxFullCells([
            ['', '', 2026, 0, 10, 'JE-OPEN', 'Opening', '611', 100, '', ''],
            ['', '', 2026, 0, 10, 'JE-OPEN', 'Opening', '111', '', 100, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('success', 'Imported 1 journal entries. Skipped 0 rows.');
        $entry = JournalEntry::where('reference', 'JE-OPEN')->first();
        $this->assertNotNull($entry);
        $this->assertSame('2026-01-10', $entry->date);
        $this->assertSame(0, (int) $entry->accounting_period);
    }

    public function test_year_month_day_without_date_creates_entry(): void
    {
        $file = $this->makeXlsxFull([
            ['', '', 2026, 11, 18, 'JE-YMD', 'YMD entry', '611', 500, '', 'Inventory cost'],
            ['', '', 2026, 11, 18, 'JE-YMD', 'YMD entry', '111', '', 500, 'Cash payment'],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('success', 'Imported 1 journal entries. Skipped 0 rows.');
        $entry = JournalEntry::where('reference', 'JE-YMD')->first();
        $this->assertNotNull($entry);
        $this->assertSame('2026-11-18', $entry->date);
        $this->assertSame(11, (int) $entry->accounting_period);
        $this->assertSame(JournalEntry::STATUS_PENDING, (int) $entry->status);
    }

    public function test_date_wins_when_both_date_and_ymd_given(): void
    {
        $file = $this->makeXlsxFull([
            ['', '2026-11-18', 2025, 1, 5, 'JE-BOTH', 'Both', '611', 500, '', ''],
            ['', '2026-11-18', 2025, 1, 5, 'JE-BOTH', 'Both', '111', '', 500, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('success', 'Imported 1 journal entries. Skipped 0 rows.');
        $entry = JournalEntry::where('reference', 'JE-BOTH')->first();
        $this->assertNotNull($entry);
        $this->assertSame('2026-11-18', $entry->date);
        $this->assertSame(11, (int) $entry->accounting_period);
    }

    public function test_invalid_year_month_day_is_skipped(): void
    {
        $file = $this->makeXlsxFull([
            ['', '', 2026, 16, 10, 'JE-BADMO', 'Bad month', '611', 100, '', ''],
            ['', '', 2026, 16, 10, 'JE-BADMO', 'Bad month', '111', '', 100, ''],
            ['', '', 2026, 2, 30, 'JE-BADDAY', 'Bad day', '611', 100, '', ''],
            ['', '', 2026, 2, 30, 'JE-BADDAY', 'Bad day', '111', '', 100, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('success', 'Imported 0 journal entries. Skipped 4 rows.');
        $this->assertSame(0, JournalEntry::count());
        $errors = $response->getSession()->get('import_errors');
        $this->assertStringContainsString('Date is not valid', $errors[0]);
    }

    public function test_special_period_month_is_stored(): void
    {
        $file = $this->makeXlsxFull([
            ['', '', 2026, 13, 5, 'JE-YE', 'Year end', '611', 100, '', ''],
            ['', '', 2026, 13, 5, 'JE-YE', 'Year end', '111', '', 100, ''],
        ]);

        $response = $this->post(route('journal-entry.import'), ['file' => $file]);

        $response->assertSessionHas('success', 'Imported 1 journal entries. Skipped 0 rows.');
        $entry = JournalEntry::where('reference', 'JE-YE')->first();
        $this->assertNotNull($entry);
        $this->assertSame('2026-12-05', $entry->date);
        $this->assertSame(13, (int) $entry->accounting_period);
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    /** Make a real file with the given content (not a fake upload). */
    private function makeRealFile(string $name, string $content, ?string $mime = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'je-real-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function makeXlsx(array $rows): UploadedFile
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->fromArray(
            ['date', 'reference', 'description', 'account_code', 'debit', 'credit', 'line_description'],
            null, 'A1'
        );
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'je-import-').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        return new UploadedFile($path, 'journal-entries.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Same as makeXlsx, but with the full template headings (journal_number + year/month/day). */
    private function makeXlsxFull(array $rows): UploadedFile
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->fromArray(JournalEntryImport::headings(), null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'je-import-').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        return new UploadedFile($path, 'journal-entries.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Same as makeXlsxFull, but written cell-by-cell: fromArray()
     * drops 0 values, so files that need a real 0 (journal number
     * 0, month 0) must be built this way.
     */
    private function makeXlsxFullCells(array $rows): UploadedFile
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        foreach (JournalEntryImport::headings() as $col => $heading) {
            $sheet->setCellValue([$col + 1, 1], $heading);
        }
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $col => $value) {
                $sheet->setCellValue([$col + 1, $rowIndex + 2], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'je-import-').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        return new UploadedFile($path, 'journal-entries.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function makeCompanyUser(): User
    {
        $user = User::create([
            'name' => 'Test Company',
            'email' => 'company@test.local',
            'password' => bcrypt('password'),
            'type' => 'company',
            'created_by' => 0,
            'is_enable_login' => 1,
            'is_active' => 1,
            'lang' => 'en',
            'email_verified_at' => now(),
        ]);
        $user->default_company_id = $user->id;
        $user->plan = '1';
        $user->save();

        return $user;
    }

    private function seedPermissions(): void
    {
        foreach (['create journal entry', 'manage journal entry'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function seedBaseRows(): void
    {
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => 'pt', 'full_name' => 'Portuguese', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('settings')->insert([
            ['name' => 'default_language', 'value' => 'en', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test', 'storage_limit' => -1, 'max_customers' => -1,
             'crm' => 1, 'project' => 1, 'hrm' => 1, 'account' => 1, 'pos' => 1, 'stock' => 1,
             'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('type', 100)->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('default_company_id')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->string('lang')->default('en');
            $table->string('plan')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->integer('permission_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->integer('permission_id');
            $table->integer('role_id');
            $table->index(['role_id', 'permission_id']);
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('languages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('email_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('from')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('from_id')->nullable();
            $table->integer('to_id')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });
        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->integer('max_customers')->default(-1);
            $table->tinyInteger('crm')->default(1);
            $table->tinyInteger('project')->default(1);
            $table->tinyInteger('hrm')->default(1);
            $table->tinyInteger('account')->default(1);
            $table->tinyInteger('pos')->default(1);
            $table->tinyInteger('stock')->default(1);
            $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('code')->default(0);
            $table->integer('type')->default(0);
            $table->integer('sub_type')->default(0);
            $table->integer('parent')->default(0);
            $table->integer('is_enabled')->default(1);
            $table->text('description')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('accounting_period')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('journal_id')->default(0);
            $table->string('source', 20)->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_items', function (Blueprint $table) {
            $table->id();
            $table->integer('journal')->default(0);
            $table->integer('account')->default(0);
            $table->text('description')->nullable();
            $table->float('debit', 15, 2)->default(0.0);
            $table->float('credit', 15, 2)->default(0.0);
            $table->timestamps();
        });
    }
}
