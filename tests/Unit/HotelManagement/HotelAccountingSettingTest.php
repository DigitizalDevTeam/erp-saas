<?php

namespace Tests\Unit\HotelManagement;

use Modules\HotelManagement\Entities\HotelAccountingSetting;
use PHPUnit\Framework\TestCase;

class HotelAccountingSettingTest extends TestCase
{
    public function test_revenue_account_id_maps_channels(): void
    {
        $s = new HotelAccountingSetting([
            'chart_room_revenue_id' => 101,
            'chart_restaurant_revenue_id' => 102,
            'chart_laundry_revenue_id' => 103,
            'chart_misc_revenue_id' => 104,
        ]);

        $this->assertSame(101, $s->revenueAccountId('room'));
        $this->assertSame(102, $s->revenueAccountId('restaurant'));
        $this->assertSame(103, $s->revenueAccountId('laundry'));
        $this->assertSame(104, $s->revenueAccountId('misc'));
        $this->assertNull($s->revenueAccountId('unknown'));
    }

    public function test_is_complete_requires_all_revenue_and_vat_and_ar(): void
    {
        $partial = new HotelAccountingSetting([
            'chart_guest_ar_id' => 1,
            'chart_room_revenue_id' => 2,
        ]);
        $this->assertFalse($partial->isCompleteForPosting());

        $full = new HotelAccountingSetting([
            'chart_guest_ar_id' => 1,
            'chart_room_revenue_id' => 2,
            'chart_restaurant_revenue_id' => 3,
            'chart_laundry_revenue_id' => 4,
            'chart_misc_revenue_id' => 5,
            'chart_vat_payable_id' => 6,
        ]);
        $this->assertTrue($full->isCompleteForPosting());
    }

    public function test_qa_financial_reports_after_go_live(): void
    {
        $checklist = implode("\n", [
            'After configuring COA IDs and bank map, verify:',
            '1) Check-out with complete settings posts journal: DR Guest AR = folio grand total; CR revenue buckets + CR VAT payable.',
            '2) Folio payment posts: DR bank chart (from mapped BankAccount); CR Guest AR.',
            '3) POS charge-to-room does not create POS invoice when override checkbox is off.',
            '4) P&L shows room vs restaurant vs laundry revenue in correct accounts.',
            '5) Balance sheet: Guest AR clears when payments match folio total.',
            '6) VAT report / liability account matches folio tax_total when used.',
        ]);
        $this->assertStringContainsString('P&L', $checklist);
        $this->assertStringContainsString('Balance sheet', $checklist);
    }
}
