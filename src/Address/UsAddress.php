<?php

declare(strict_types=1);

namespace Watchman\Address;

use Watchman\Norm\Iso3166;
use Watchman\Search\Address;
use Watchman\Support\Go;

/**
 * Port of pkg/usaddress (the default, dependency-free address parser) and
 * pkg/address.ParseAddress (non-libpostal build).
 */
final class UsAddress
{
    /**
     * Parse a free-form address into Watchman's Address shape.
     */
    public static function parse(string $input): Address
    {
        $a = self::standardize($input);
        if ($a['POBox'] !== '') {
            $line1 = 'PO Box ' . $a['POBox'];
        } elseif ($a['RuralRoute'] !== '') {
            $line1 = 'RR ' . $a['RuralRoute'];
        } elseif ($a['HighwayContract'] !== '') {
            $line1 = 'HC ' . $a['HighwayContract'];
        } else {
            $parts = array_filter([$a['PrimaryNumber'], $a['StreetPredir'], $a['StreetName'], $a['StreetSuffix'], $a['StreetPostdir']], static fn ($p) => $p !== '');
            $line1 = implode(' ', $parts);
        }

        return new Address(
            line1: $line1,
            line2: $a['SecondaryUnit'],
            city: $a['City'],
            postalCode: $a['ZIPCode'] . $a['Plus4'],
            state: $a['State'],
            country: $a['Country'],
        );
    }

    /** @return array<string,string> */
    public static function standardize(string $addressStr): array
    {
        $addr = array_fill_keys(['PrimaryNumber', 'StreetPredir', 'StreetName', 'StreetSuffix', 'StreetPostdir', 'SecondaryUnit',
            'City', 'State', 'ZIPCode', 'Plus4', 'POBox', 'RuralRoute', 'HighwayContract', 'Country'], '');
        $addressStr = Go::upper(str_replace("\r", '', $addressStr));
        $lines = array_map([Go::class, 'trimSpace'], explode("\n", $addressStr));
        $lines = array_values(array_filter($lines, static fn ($l) => Go::trimSpace($l) !== ''));
        $lines = array_values(array_filter($lines, static fn ($l) => !str_starts_with($l, 'C/O') && !str_starts_with($l, "\u{2105}")));
        $lines = array_map([self::class, 'removePunctuation'], $lines);

        $deliveryLines = [];
        $lastLine = '';
        $n = count($lines);
        if ($n >= 2) {
            $lastLine = $lines[$n - 1];
            for ($i = $n - 2; $i >= 0; $i--) {
                if (preg_match('/[0-9]/', $lines[$i]) || self::isPOBoxLine($lines[$i])) {
                    array_unshift($deliveryLines, $lines[$i]);
                }
            }
        } elseif ($n === 1) {
            $line = $lines[0];
            $parts = explode(',', $line, 2);
            if (count($parts) === 2) {
                $deliveryLines[] = Go::trimSpace($parts[0]);
                $lastLine = Go::trimSpace($parts[1]);
            } else {
                $words = Go::fields($line);
                $stateIndex = -1;
                for ($i = count($words) - 1; $i >= 0; $i--) {
                    if (self::standardizeState($words[$i]) !== '' && $i + 1 < count($words) && self::isZipCode($words[$i + 1])) {
                        $stateIndex = $i;
                        break;
                    }
                }
                if ($stateIndex > 0) {
                    $deliveryLines[] = Go::trimSpace(implode(' ', array_slice($words, 0, $stateIndex - 1)));
                    $lastLine = Go::trimSpace(implode(' ', array_slice($words, $stateIndex - 1)));
                } else {
                    $deliveryLines[] = $line;
                }
            }
        }

        self::parseCityStateZip($lastLine, $addr);
        foreach ($deliveryLines as $line) {
            if (self::isPOBoxLine($line)) {
                $addr['POBox'] = $line;
            } else {
                self::parseDeliveryLine($line, $addr);
            }
        }

        return $addr;
    }

    private static function parseCityStateZip(string $line, array &$addr): void
    {
        if ($line === '') {
            return;
        }
        $c = Go::fields($line);
        if ($c === []) {
            return;
        }
        $maybeCountry = $c[count($c) - 1];
        $up = Go::upper($maybeCountry);
        if (in_array($up, ['US', 'USA', 'UNITED STATES'], true)) {
            $addr['Country'] = 'UNITED STATES';
            array_pop($c);
        } elseif (Iso3166::lookupCode($maybeCountry) !== '') {
            $addr['Country'] = $maybeCountry;
            array_pop($c);
        }
        if ($c === []) {
            return;
        }
        $zip = Go::trimSpace($c[count($c) - 1]);
        if (self::isZipCode($zip)) {
            $addr['ZIPCode'] = $zip;
            array_pop($c);
        }
        if ($c === []) {
            return;
        }
        if (str_contains($addr['ZIPCode'], '-')) {
            [$addr['ZIPCode'], $addr['Plus4']] = explode('-', $addr['ZIPCode'], 2) + [1 => ''];
            $addr['Plus4'] = explode('-', $addr['Plus4'])[0];
        }
        $n = count($c);
        if ($n >= 2 && in_array($c[$n - 1], ['AE', 'AA', 'AP'], true)) {
            $addr['State'] = array_pop($c);
            $addr['City'] = implode(' ', $c);
        } else {
            $found = false;
            for ($i = $n; $i > 0; $i--) {
                $abbr = self::standardizeState(implode(' ', array_slice($c, $i - 1)));
                if ($abbr !== '') {
                    $addr['State'] = $abbr;
                    $addr['City'] = implode(' ', array_slice($c, 0, $i - 1));
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $addr['City'] = implode(' ', $c);
            }
        }
        $addr['City'] = Go::trimSpace(Go::trimSuffix($addr['City'], ','));
    }

    private static function parseDeliveryLine(string $line, array &$addr): void
    {
        $c = Go::fields($line);
        $n = count($c);
        $i = 0;
        if ($n === 0) {
            return;
        }
        if (in_array($c[0], ['PSC', 'UNIT', 'CMR'], true)) {
            $addr['POBox'] = implode(' ', $c);

            return;
        }
        if (in_array($c[0], ['PO', 'P.O', 'POBOX', 'P.O.BOX'], true)) {
            $addr['POBox'] = implode(' ', $c);

            return;
        }
        if (in_array($c[0], ['RR', 'RFD', 'RD'], true)) {
            $addr['RuralRoute'] = implode(' ', $c);

            return;
        }
        if ($c[0] === 'HC') {
            $addr['HighwayContract'] = implode(' ', $c);

            return;
        }
        if (preg_match('/^\d+([- ]\d+)?(\s*\d+\/\d+)?$/', $c[$i])) {
            $addr['PrimaryNumber'] = $c[$i];
            $i++;
            if ($i < $n && preg_match('/^\d+\/\d+$/', $c[$i])) {
                $addr['PrimaryNumber'] .= ' ' . $c[$i];
                $i++;
            }
        }
        $dir = UsAddressData::DIRECTIONALS;
        if ($i < $n && ($dir[$c[$i]] ?? '') !== '') {
            $addr['StreetPredir'] = $dir[$c[$i]];
            $i++;
        }
        $name = '';
        while ($i < $n && !isset(UsAddressData::STREET_SUFFIXES[$c[$i]]) && !isset(UsAddressData::SECONDARY_UNITS[$c[$i]]) && ($dir[$c[$i]] ?? '') === '') {
            $name .= $c[$i] . ' ';
            $i++;
        }
        $addr['StreetName'] = Go::trimSpace($name);
        if ($i < $n && isset(UsAddressData::STREET_SUFFIXES[$c[$i]])) {
            $addr['StreetSuffix'] = UsAddressData::STREET_SUFFIXES[$c[$i]];
            $i++;
        }
        if ($i < $n && ($dir[$c[$i]] ?? '') !== '') {
            $addr['StreetPostdir'] = $dir[$c[$i]];
            $i++;
        }
        if ($i < $n) {
            $unit = '';
            for (; $i < $n; $i++) {
                $unit .= $c[$i] . ' ';
            }
            $addr['SecondaryUnit'] = Go::trimSpace($unit);
        }
    }

    private static function removePunctuation(string $s): string
    {
        // Go: [^\w\s#&-/] with ASCII \w / \s (&-/ is a byte range).
        $s = (string) preg_replace('/[^0-9A-Za-z_\t\n\f\r #&-\/]/u', '', $s);

        return (string) preg_replace('/#([0-9])/', '# $1', $s);
    }

    private static function isZipCode(string $s): bool
    {
        return (bool) preg_match('/^\d{5}(-\d{4})?$/', Go::trimSpace($s));
    }

    private static function isPOBoxLine(string $s): bool
    {
        $s = Go::trimSpace($s);

        return str_starts_with($s, 'PO BOX') || str_starts_with($s, 'P.O BOX') || str_starts_with($s, 'P.O. BOX');
    }

    private static function standardizeState(string $s): string
    {
        return UsAddressData::STATES[Go::trimSpace(Go::upper($s))] ?? '';
    }
}
