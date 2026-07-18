<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\JournalEntryPoster;
use App\Services\Accounting\OpeningBalanceCarryForwardService;
use Tests\TestCase;

class OpeningBalanceCarryForwardServiceTest extends TestCase
{
    public function test_reference_key_uses_configured_prefix(): void
    {
        $service = new OpeningBalanceCarryForwardService($this->createMock(JournalEntryPoster::class));

        $this->assertSame('OPEN-2027-P0', $service->referenceKey(2027));
    }

    /**
     * @dataProvider openingBalanceClassProvider
     */
    public function test_is_opening_balance_class(string $code, bool $expected): void
    {
        $this->assertSame($expected, OpeningBalanceCarryForwardService::isOpeningBalanceClass($code));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function openingBalanceClassProvider(): array
    {
        return [
            'class 1 asset' => ['11', true],
            'class 2 liability' => ['21', true],
            'class 5 equity' => ['51', true],
            'class 6 expense' => ['61', false],
            'class 7 income' => ['71', false],
            'class 8 financial' => ['81', false],
            'non numeric' => ['', false],
            'code with separators' => ['41-100', true],
        ];
    }
}
