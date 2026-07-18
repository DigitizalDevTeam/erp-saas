<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\JournalEntryPoster;
use App\Services\Accounting\ManualOpeningBalanceService;
use Tests\TestCase;

class ManualOpeningBalanceServiceTest extends TestCase
{
    public function test_reference_key_uses_manual_prefix(): void
    {
        $service = new ManualOpeningBalanceService($this->createMock(JournalEntryPoster::class));

        $this->assertSame('OPEN-MAN-2027-P0', $service->referenceKey(2027));
    }
}
