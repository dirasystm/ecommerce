<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waimart\Core\ValidationException;
use Waimart\Core\Validator;

final class ValidatorTest extends TestCase
{
    public static function phones(): array
    {
        return [
            ['0712345678', '254712345678'],
            ['0712 345 678', '254712345678'],
            ['+254712345678', '254712345678'],
            ['254712345678', '254712345678'],
            ['712345678', '254712345678'],
            ['0110-123-456', '254110123456'],
            ['0812345678', null],     // not a mobile prefix
            ['07123456', null],       // too short
            ['abc', null],
        ];
    }

    #[DataProvider('phones')]
    public function testNormalizesKenyanPhones(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, Validator::normalizePhone($raw));
    }

    public function testDisplayPhone(): void
    {
        $this->assertSame('0712 345 678', Validator::displayPhone('254712345678'));
    }

    public function testRules(): void
    {
        $errors = Validator::check(
            ['name' => '', 'email' => 'bad', 'qty' => '2.5', 'method' => 'card', 'pw' => 'short', 'note' => ''],
            ['name' => 'required', 'email' => 'required|email', 'qty' => 'int', 'method' => 'in:cod,mpesa_till',
             'pw' => 'password', 'note' => 'nullable|max:5'],
        );
        $this->assertSame(['name', 'email', 'qty', 'method', 'pw'], array_keys($errors));
    }

    public function testValidInputPasses(): void
    {
        $this->assertSame([], Validator::check(
            ['email' => 'a@b.co', 'phone' => '0712345678', 'amount' => '1500.50', 'code' => 'sgh7k2l9xq', 'p' => 'abc12345', 'p2' => 'abc12345'],
            ['email' => 'email', 'phone' => 'phone', 'amount' => 'decimal|gte:1', 'code' => 'mpesa_code', 'p' => 'password', 'p2' => 'same:p'],
        ));
    }

    public function testValidateThrows(): void
    {
        $this->expectException(ValidationException::class);
        Validator::validate(['x' => ''], ['x' => 'required']);
    }
}
