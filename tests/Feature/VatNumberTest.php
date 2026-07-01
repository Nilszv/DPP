<?php

namespace Tests\Feature;

use App\Support\VatNumber;
use Tests\TestCase;

/** Server-side VAT canonicalization + validation (no DB needed, but needs the app/config). */
class VatNumberTest extends TestCase
{
    public function test_canonical_normalizes_formatting_and_ensures_prefix(): void
    {
        $this->assertSame('LV40003011283', VatNumber::canonical('LV', 'LV40003011283'));
        $this->assertSame('LV40003011283', VatNumber::canonical('LV', 'lv 4000 3011 283'));
        $this->assertSame('LV40003011283', VatNumber::canonical('LV', '40003011283'));   // prefix added
        $this->assertSame('EL123456789', VatNumber::canonical('GR', '123456789'));        // Greece = EL
        $this->assertSame('123456789', VatNumber::canonical('US', '12-3456789'));         // no prefix
        $this->assertNull(VatNumber::canonical('LV', '  '));
    }

    public function test_is_valid_enforces_the_national_format(): void
    {
        $this->assertTrue(VatNumber::isValid('LV', 'LV40003011283'));
        $this->assertTrue(VatNumber::isValid('LV', '40003011283'));   // prefix inferred, still valid
        $this->assertFalse(VatNumber::isValid('LV', 'LV123'));        // too short
        $this->assertFalse(VatNumber::isValid('LV', 'LV4000301128X')); // letter where digits expected
        $this->assertTrue(VatNumber::isValid('US', 'anything-goes')); // no format configured
        $this->assertTrue(VatNumber::isValid('LV', ''));              // empty handled by requiredness
    }

    public function test_country_has_vat(): void
    {
        $this->assertTrue(VatNumber::countryHasVat('LV'));
        $this->assertFalse(VatNumber::countryHasVat('US'));
        $this->assertFalse(VatNumber::countryHasVat(null));
    }
}
