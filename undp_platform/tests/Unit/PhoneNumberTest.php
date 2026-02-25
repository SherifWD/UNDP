<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_normalize_strips_libya_trunk_zero(): void
    {
        $normalized = PhoneNumber::normalize('+218', '0910000001');

        $this->assertSame('+218', $normalized['country_code']);
        $this->assertSame('910000001', $normalized['phone']);
        $this->assertSame('+218910000001', $normalized['phone_e164']);
    }

    public function test_normalize_strips_duplicated_country_digits_in_phone_field(): void
    {
        $normalized = PhoneNumber::normalize('+218', '218910000001');

        $this->assertSame('910000001', $normalized['phone']);
        $this->assertSame('+218910000001', $normalized['phone_e164']);
    }

    public function test_normalize_handles_international_prefix_in_phone_field(): void
    {
        $normalized = PhoneNumber::normalize('+218', '00218910000001');

        $this->assertSame('910000001', $normalized['phone']);
        $this->assertSame('+218910000001', $normalized['phone_e164']);
    }
}
