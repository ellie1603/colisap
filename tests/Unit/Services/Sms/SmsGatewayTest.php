<?php

namespace Tests\Unit\Services\Sms;

use App\Services\Sms\SmsGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmsGatewayTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function contactNumbers(): array
    {
        return [
            'already in gateway form' => ['09171234567', '09171234567'],
            'international with plus' => ['+639171234567', '09171234567'],
            'international without plus' => ['639171234567', '09171234567'],
            'missing the leading zero' => ['9171234567', '09171234567'],
            'spaces and dashes' => ['0917-123 4567', '09171234567'],
            'two numbers, first is used' => ['09171234567 / 09181234567', '09171234567'],
            'landline first, mobile second' => ['(036) 320-1234, 09181234567', '09181234567'],
            'landline only' => ['(036) 320-1234', null],
            'too short' => ['0917123456', null],
            'text' => ['none', null],
            'empty' => [null, null],
        ];
    }

    #[DataProvider('contactNumbers')]
    public function test_contact_numbers_are_normalized_to_a_philippine_mobile_number(?string $contactNumber, ?string $expected): void
    {
        $this->assertSame($expected, SmsGateway::normalizeNumber($contactNumber));
    }
}
