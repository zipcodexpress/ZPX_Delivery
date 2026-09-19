<?php
declare(strict_types=1);
namespace Zpx\Identity;

final class Input
{
    public static function fields(array $input, array $required, array $optional = []): void
    {
        if (array_diff($required, array_keys($input)) || array_diff(array_keys($input), [...$required,...$optional])) {
            throw new Failure(422,'INVALID_INPUT','Required fields are missing or unsupported fields were supplied.');
        }
    }
    public static function text(mixed $value, int $min, int $max): string
    {
        if (!is_string($value) || strlen($value)<$min || strlen($value)>$max || str_contains($value,"\0")) {
            throw new Failure(422,'INVALID_INPUT','A field has an invalid type or length.');
        }
        return $value;
    }
    public static function contact(string $kind, mixed $value): string
    {
        $value = trim(self::text($value,1,254));
        if ($kind==='EMAIL' && filter_var($value,FILTER_VALIDATE_EMAIL)) { return strtolower($value); }
        if ($kind==='PHONE' && preg_match('/^\+[1-9][0-9]{7,14}$/D',$value)) { return $value; }
        throw new Failure(422,'INVALID_CONTACT','Use a valid email address or E.164 phone number.');
    }
    public static function address(mixed $value): array
    {
        if (!is_array($value)) { throw new Failure(422,'INVALID_INPUT','Address is required.'); }
        self::fields($value,['line1','city','region','postal_code','country_code'],['line2']);
        foreach ($value as $key=>$text) { self::text($text,$key==='line2'?0:1,200); }
        if (!preg_match('/^[A-Z]{2}$/D',$value['country_code'])) { throw new Failure(422,'INVALID_INPUT','Use a two-letter uppercase country code.'); }
        return $value;
    }
}
