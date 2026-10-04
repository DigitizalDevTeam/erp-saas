<?php

namespace Tests\Unit\Support;

use App\Support\MailConfig;
use PHPUnit\Framework\TestCase;

class MailConfigTest extends TestCase
{
    public function test_company_values_win_over_systems_values(): void
    {
        $merged = MailConfig::mergeSettings(
            ['mail_host' => 'mail.company.example', 'mail_port' => '465'],
            ['mail_host' => 'mail.systems.example', 'mail_port' => '587', 'mail_username' => 'systems-user']
        );

        $this->assertSame('mail.company.example', $merged['mail_host']);
        $this->assertSame('465', $merged['mail_port']);
    }

    public function test_empty_company_values_fall_back_to_systems_values(): void
    {
        $merged = MailConfig::mergeSettings(
            ['mail_host' => '', 'mail_from_address' => ''],
            ['mail_host' => 'mail.systems.example', 'mail_from_address' => 'no-reply@systems.example']
        );

        $this->assertSame('mail.systems.example', $merged['mail_host']);
        $this->assertSame('no-reply@systems.example', $merged['mail_from_address']);
    }

    public function test_all_mail_fields_are_always_present(): void
    {
        $merged = MailConfig::mergeSettings([], []);

        $this->assertSame([
            'mail_driver',
            'mail_host',
            'mail_port',
            'mail_username',
            'mail_password',
            'mail_encryption',
            'mail_from_address',
            'mail_from_name',
        ], array_keys($merged));
    }
}
