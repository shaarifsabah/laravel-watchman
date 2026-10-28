<?php

declare(strict_types=1);

namespace Watchman\Norm;

use Watchman\Support\Data;
use Watchman\Support\Go;

/**
 * Port of internal/norm/phone.go and github.com/dongri/phonenumber v0.1.13.
 */
final class Phone
{
    /** @var array<string,string> */
    private static array $memo = [];

    public static function normalize(string $input): string
    {
        if (isset(self::$memo[$input])) {
            return self::$memo[$input];
        }
        $country = self::byNumber($input, false);
        if ($country === null || $country['a2'] === '') {
            $country = self::byNumber($input, true);
        }
        if ($country === null || $country['a2'] === '') {
            $mobile = self::byMobileNumber($input);
            $country = $mobile[0] ?? null;
        }
        if ($country === null || $country['a2'] === '') {
            $out = self::clean($input);
        } else {
            $number = self::parse($input, $country['a2']);
            $out = self::clean(Go::or($number, $input));
        }
        if (count(self::$memo) > 10000) {
            self::$memo = [];
        }

        return self::$memo[$input] = $out;
    }

    private static function clean(string $s): string
    {
        return strtr($s, ['+' => '', ' ' => '', '-' => '', '(' => '', ')' => '']);
    }

    /** @return list<array{a2:string,a3:string,cc:string,name:string,mobile:list<string>,lengths:list<int>}> */
    private static function data(): array
    {
        return Data::get('phone');
    }

    private static function startsWith(string $number, string $prefix): bool
    {
        return $prefix === '' || strncmp($number, $prefix, strlen($prefix)) === 0;
    }

    /** phonenumber.GetISO3166ByNumber */
    public static function byNumber(string $number, bool $withLandLine): ?array
    {
        $found = null;
        foreach (self::data() as $i) {
            foreach ($i['lengths'] as $l) {
                if (self::startsWith($number, $i['cc']) && strlen($number) === strlen($i['cc']) + $l) {
                    foreach ($i['mobile'] as $w) {
                        if (self::startsWith($number, $i['cc'] . $w)) {
                            return $i;
                        }
                    }
                    if ($withLandLine) {
                        $found = $i;
                        break;
                    }
                }
            }
        }

        return $found;
    }

    /** phonenumber.GetISO3166ByMobileNumber */
    public static function byMobileNumber(string $number): array
    {
        $result = [];
        foreach (self::data() as $i) {
            foreach ($i['lengths'] as $l) {
                if (strlen($number) === $l) {
                    foreach ($i['mobile'] as $w) {
                        if ($w !== '' && Go::hasPrefix($number, $w)) {
                            $result[] = $i;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /** phonenumber.Parse (mobile only) */
    public static function parse(string $number, string $country): string
    {
        $number = str_replace(' ', '', $number);
        $country = str_replace(' ', '', $country);
        if (str_starts_with($number, '+') && $country === '') {
            return '';
        }
        $number = (string) preg_replace('/\D/', '', $number);
        $iso = self::byCountry($country);
        $national = str_starts_with($number, '0') && !str_starts_with($number, '00');

        if (self::startsWith($number, $iso['cc'])) {
            $without = $iso['cc'] === '' ? $number : self::replaceOnce($number, $iso['cc']);
            if (str_starts_with($without, '0')) {
                $without = self::replaceOnce($without, '0');
            }
            $number = $iso['cc'] . $without;
        }
        if (!in_array($iso['a3'], ['GAB', 'CIV', 'COG'], true)) {
            $number = (string) preg_replace('/^0+/', '', $number);
        }
        if ($iso['a3'] === 'RUS' && strlen($number) === 11 && str_starts_with($number, '89')) {
            $number = (string) preg_replace('/^8+/', '', $number);
        }
        $n = strlen($number);
        if (in_array($n, $iso['lengths'], true)) {
            $cc = $iso['cc'];
            if ($cc === '' || $national || !self::startsWith($number, $cc) || !in_array($n - strlen($cc), $iso['lengths'], true)) {
                $number = $cc . $number;
            }
        }

        return self::validate($number, $iso, false) ? $number : '';
    }

    private static function replaceOnce(string $s, string $old): string
    {
        $pos = strpos($s, $old);

        return $pos === false ? $s : substr_replace($s, '', $pos, strlen($old));
    }

    private static function byCountry(string $country): array
    {
        $empty = ['a2' => '', 'a3' => '', 'cc' => '', 'name' => '', 'mobile' => [], 'lengths' => []];
        $up = strtoupper($country);
        $data = self::data();
        switch (strlen($country)) {
            case 0:
                return $data[0];
            case 2:
                foreach ($data as $i) {
                    if ($i['a2'] === $up) {
                        return $i;
                    }
                }

                return $empty;
            case 3:
                foreach ($data as $i) {
                    if ($i['a3'] === $up) {
                        return $i;
                    }
                }

                return $empty;
            default:
                foreach ($data as $i) {
                    if (strtoupper($i['name']) === $up) {
                        return $i;
                    }
                }

                return $empty;
        }
    }

    private static function validate(string $number, array $iso, bool $withLandLine): bool
    {
        if ($iso['lengths'] === []) {
            return false;
        }
        if ($withLandLine) {
            foreach ($iso['lengths'] as $l) {
                if (self::startsWith($number, $iso['cc']) && strlen($number) === strlen($iso['cc']) + $l) {
                    return true;
                }
            }

            return false;
        }
        // regexp `^cc` ReplaceAllString removes a single leading occurrence
        if ($iso['cc'] !== '' && self::startsWith($number, $iso['cc'])) {
            $number = substr($number, strlen($iso['cc']));
        }
        foreach ($iso['lengths'] as $l) {
            if ($l === strlen($number)) {
                foreach ($iso['mobile'] as $w) {
                    if (self::startsWith($number, $w)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
