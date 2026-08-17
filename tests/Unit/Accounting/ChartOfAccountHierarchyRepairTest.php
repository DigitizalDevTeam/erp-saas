<?php

namespace Tests\Unit\Accounting;

use App\Support\ChartOfAccountHierarchy;
use PHPUnit\Framework\TestCase;

class ChartOfAccountHierarchyRepairTest extends TestCase
{
    public function test_skips_csv_sibling_prefix_4210_for_custom_vendor_code(): void
    {
        $csvParents = ['421' => '42', '4210' => '42'];
        $csvLevels = ['421' => 'sub account', '4210' => 'sub account'];

        $this->assertTrue(
            ChartOfAccountHierarchy::shouldSkipCustomPrefixParent('4210001', '4210', $csvParents, $csvLevels)
        );
        $this->assertFalse(
            ChartOfAccountHierarchy::shouldSkipCustomPrefixParent('4210001', '421', $csvParents, $csvLevels)
        );
    }

    public function test_does_not_skip_true_parent_prefix_42_for_supplier_sub(): void
    {
        $csvParents = ['42' => null, '421' => '42', '4210' => '42'];
        $csvLevels = ['42' => 'parent account - parent', '421' => 'sub account', '4210' => 'sub account'];

        $this->assertFalse(
            ChartOfAccountHierarchy::shouldSkipCustomPrefixParent('4210001', '42', $csvParents, $csvLevels)
        );
    }
}
