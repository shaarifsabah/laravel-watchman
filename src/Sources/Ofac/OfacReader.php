<?php

declare(strict_types=1);

namespace Watchman\Sources\Ofac;

use RuntimeException;
use Watchman\Support\Csv;
use Watchman\Support\Go;

/**
 * Port of pkg/sources/ofac/reader.go. Reads sdn.csv, add.csv, alt.csv and
 * sdn_comments.csv (or the cons_* Non-SDN equivalents).
 */
final class OfacReader
{
    /**
     * @param array<string,string> $files filename => local path
     * @return array{sdns: list<array>, addresses: array<string,list<array>>, alts: array<string,list<array>>, comments: array<string,list<array>>, hash: string}
     */
    public static function read(array $files): array
    {
        $res = ['sdns' => [], 'addresses' => [], 'alts' => [], 'comments' => [], 'hash' => ''];
        $hashes = ['', '', '', ''];
        foreach ($files as $filename => $path) {
            $name = strtolower(basename((string) $filename));
            switch ($name) {
                case 'add.csv':
                case 'cons_add.csv':
                    foreach (Csv::rows($path) as $r) {
                        if (count($r) !== 6) {
                            continue;
                        }
                        $r = self::replaceNull($r);
                        $res['addresses'][$r[0]][] = [
                            'entityID' => $r[0], 'addressID' => $r[1], 'address' => $r[2],
                            'cityStateProvincePostalCode' => $r[3], 'country' => $r[4], 'addressRemarks' => $r[5],
                        ];
                    }
                    $hashes[0] = hash_file('sha256', $path);
                    break;
                case 'alt.csv':
                case 'cons_alt.csv':
                    foreach (Csv::rows($path) as $r) {
                        if (count($r) !== 5) {
                            continue;
                        }
                        $r = self::replaceNull($r);
                        $res['alts'][$r[0]][] = [
                            'entityID' => $r[0], 'alternateID' => $r[1], 'alternateType' => $r[2],
                            'alternateName' => $r[3], 'alternateRemarks' => $r[4],
                        ];
                    }
                    $hashes[1] = hash_file('sha256', $path);
                    break;
                case 'sdn.csv':
                case 'cons_prim.csv':
                    foreach (Csv::rows($path) as $r) {
                        if (count($r) !== 12) {
                            continue;
                        }
                        $r = self::replaceNull($r);
                        $res['sdns'][] = [
                            'entityID' => $r[0], 'sdnName' => $r[1], 'sdnType' => $r[2],
                            'program' => self::splitPrograms($r[3]), 'title' => $r[4], 'callSign' => $r[5],
                            'vesselType' => $r[6], 'tonnage' => $r[7], 'grossRegisteredTonnage' => $r[8],
                            'vesselFlag' => $r[9], 'vesselOwner' => $r[10], 'remarks' => $r[11],
                        ];
                    }
                    $hashes[2] = hash_file('sha256', $path);
                    break;
                case 'sdn_comments.csv':
                case 'cons_comments.csv':
                    foreach (Csv::rows($path) as $r) {
                        if (count($r) !== 2) {
                            continue;
                        }
                        $r = self::replaceNull($r);
                        $res['comments'][$r[0]][] = [
                            'entityID' => $r[0], 'remarksExtended' => $r[1],
                            'digitalCurrencyAddresses' => self::digitalCurrencyAddresses($r[1]),
                        ];
                    }
                    $hashes[3] = hash_file('sha256', $path);
                    break;
                default:
                    throw new RuntimeException("error: file $filename does not have a handler for processing");
            }
        }
        $res['hash'] = hash('sha256', implode('', $hashes));

        // merge spillover remarks from sdn_comments.csv
        foreach ($res['sdns'] as &$sdn) {
            $comments = $res['comments'][$sdn['entityID']] ?? [];
            if ($comments === []) {
                continue;
            }
            $b = $sdn['remarks'];
            foreach ($comments as $c) {
                if ($c['entityID'] === $sdn['entityID']) {
                    $b .= $c['remarksExtended'];
                }
            }
            $sdn['remarks'] = $b;
        }
        unset($sdn);

        return $res;
    }

    /** @param list<string> $s @return list<string> */
    public static function replaceNull(array $s): array
    {
        foreach ($s as $i => $v) {
            $s[$i] = Go::trimSpace(str_contains($v, '-0-') ? str_replace('-0-', '', $v) : $v);
        }

        return $s;
    }

    /** @return list<string> */
    public static function splitPrograms(string $in): array
    {
        if ($in === '') {
            return [];
        }
        if (strpbrk($in, '[];') === false) {
            return [Go::trimSpace($in)];
        }
        $cleaned = Go::replace($in, ['] [', '; ', ']', '', '[', '']);
        if ($cleaned === '') {
            return [];
        }

        return array_map([Go::class, 'trimSpace'], explode('; ', $cleaned));
    }

    private const CURRENCIES = ['XBT', 'ETH', 'XMR', 'LTC', 'ZEC', 'DASH', 'BTG', 'ETC', 'BSV', 'BCH', 'XVG', 'USDC', 'USDT', 'XRP', 'TRX', 'ARB', 'BSC'];

    /** @return list<array{Currency:string,Address:string}> */
    public static function digitalCurrencyAddresses(string $remarks): array
    {
        if (!str_contains($remarks, 'Currency Address')) {
            return [];
        }
        $out = [];
        foreach (preg_split('/[;.]/', $remarks, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $part = Go::trimSpace($part);
            if ($part === '') {
                continue;
            }
            foreach (self::CURRENCIES as $currency) {
                if (!str_contains($part, $currency)) {
                    continue;
                }
                $marker = ' ' . $currency . ' ';
                $idx = strpos($part, $marker);
                if ($idx === false) {
                    continue;
                }
                $start = $idx + strlen($marker);
                if ($start >= strlen($part)) {
                    continue;
                }
                $end = $start;
                $len = strlen($part);
                while ($end < $len && $part[$end] !== ' ' && $part[$end] !== ';' && $part[$end] !== '.') {
                    $end++;
                }
                if ($end > $start) {
                    $out[] = ['Currency' => $currency, 'Address' => substr($part, $start, $end - $start)];
                    break;
                }
            }
        }

        return $out;
    }
}
