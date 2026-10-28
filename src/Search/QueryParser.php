<?php

declare(strict_types=1);

namespace Watchman\Search;

use InvalidArgumentException;
use Watchman\Address\UsAddress;
use Watchman\Norm\Country;
use Watchman\Prepare\Gender as GenderNormalizer;
use Watchman\Support\Go;
use Watchman\Support\GoTime;

/**
 * Port of internal/search/api_search.go readSearchRequest & option parsing.
 *
 * Accepts query parameters as a multimap (repeated keys allowed, like Go's url.Values):
 *   ['name' => ['Dmitry Khoroshev'], 'type' => ['person'], 'gov_passport' => ['RU:2018278055']]
 * Plain scalar values and PHP-style arrays are accepted too.
 */
final class QueryParser
{
    private const DATE_LAYOUTS = ['2006-01-02', '2006-01', '2006'];

    /** @var array<string, list<string>> */
    private array $values;
    /** @var array<string,bool> */
    private array $read = [];

    public function __construct(array $params)
    {
        $this->values = [];
        foreach ($params as $k => $v) {
            $k = (string) $k;
            if (is_array($v)) {
                $this->values[$k] = array_values(array_map(static fn ($x) => is_scalar($x) ? (string) $x : '', $v));
            } elseif ($v !== null) {
                $this->values[$k] = [is_bool($v) ? ($v ? 'true' : 'false') : (string) $v];
            }
        }
    }

    /**
     * Parse a raw query string keeping repeated keys (?altNames=a&altNames=b).
     */
    public static function fromQueryString(string $qs): self
    {
        $params = [];
        foreach ($qs === '' ? [] : explode('&', $qs) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $k = urldecode($k);
            $k = (string) preg_replace('/\[\]$/', '', $k);
            $params[$k][] = urldecode($v);
        }

        return new self($params);
    }

    /** @return array<string, list<string>> all parameters (multimap) */
    public function all(): array
    {
        return $this->values;
    }

    public function get(string $name): string
    {
        $this->read[$name] = true;

        return $this->values[$name][0] ?? '';
    }

    /** @return list<string> */
    public function getAll(string $name): array
    {
        $this->read[$name] = true;

        return $this->values[$name] ?? [];
    }

    /** @return list<string> sorted keys with the prefix */
    public function withPrefix(string $prefix): array
    {
        $out = array_values(array_filter(array_keys($this->values), static fn ($k) => str_starts_with((string) $k, $prefix)));
        sort($out, SORT_STRING);

        return array_map('strval', $out);
    }

    /** @return list<string> */
    public function unused(): array
    {
        $out = array_values(array_filter(array_map('strval', array_keys($this->values)), fn ($k) => !isset($this->read[$k])));
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Build the normalized query entity.
     *
     * @param (callable(string):Address)|null $addressParser
     */
    public function entity(?callable $addressParser = null): Entity
    {
        $req = new Entity();
        $req->name = Go::trimSpace($this->get('name'));
        $req->type = Go::trimSpace(Go::lower($this->get('type')));
        $req->source = Go::or($this->get('source'), SourceList::API_REQUEST);
        $req->sourceId = $this->get('sourceID');

        switch ($req->type) {
            case EntityType::PERSON:
                $req->person = new Person(
                    name: $req->name,
                    altNames: $this->getAll('altNames'),
                    gender: GenderNormalizer::normalize($this->get('gender')),
                    birthDate: self::readDate($this->get('birthDate')),
                    deathDate: self::readDate($this->get('deathDate')),
                    titles: $this->getAll('titles'),
                    governmentIDs: $this->governmentIDs(),
                );
                break;
            case EntityType::BUSINESS:
            case EntityType::ORGANIZATION:
                $class = $req->type === EntityType::BUSINESS ? Business::class : Organization::class;
                $rec = new $class(
                    $req->name,
                    $this->getAll('altNames'),
                    self::readDate($this->get('created')),
                    self::readDate($this->get('dissolved')),
                    $this->governmentIDs(),
                );
                if ($req->type === EntityType::BUSINESS) {
                    $req->business = $rec;
                } else {
                    $req->organization = $rec;
                }
                break;
            case EntityType::AIRCRAFT:
                $req->aircraft = new Aircraft(
                    name: $req->name,
                    altNames: $this->getAll('altNames'),
                    type: $this->get('aircraftType'),
                    flag: $this->get('flag'),
                    built: self::readDate($this->get('built')),
                    icaoCode: $this->get('icaoCode'),
                    model: $this->get('model'),
                    serialNumber: $this->get('serialNumber'),
                );
                break;
            case EntityType::VESSEL:
                $req->vessel = new Vessel(
                    name: $req->name,
                    altNames: $this->getAll('altNames'),
                    imoNumber: $this->get('imoNumber'),
                    type: $this->get('vesselType'),
                    flag: $this->get('flag'),
                    built: self::readDate($this->get('built')),
                    model: $this->get('model'),
                    mmsi: $this->get('mmsi'),
                    callSign: $this->get('callSign'),
                    owner: $this->get('owner'),
                );
                if (($v = Go::trimSpace($this->get('tonnage'))) !== '') {
                    $req->vessel->tonnage = self::readInt($v, 'reading vessel tonnage');
                }
                if (($v = Go::trimSpace($this->get('grossRegisteredTonnage'))) !== '') {
                    $req->vessel->grossRegisteredTonnage = self::readInt($v, 'reading vessel GrossRegisteredTonnage');
                }
                break;
        }

        $req->contact->emailAddresses = $this->strings('email', 'emailAddress', 'emailAddresses');
        $req->contact->phoneNumbers = $this->strings('phone', 'phoneNumber', 'phoneNumbers');
        $req->contact->faxNumbers = $this->strings('fax', 'faxNumber', 'faxNumbers');
        $req->contact->websites = $this->strings('website', 'websites');
        $req->contact->domains = $this->strings('domain', 'domains');

        $parse = $addressParser ?? [UsAddress::class, 'parse'];
        foreach ($this->strings('address', 'addresses') as $input) {
            $addr = $parse($input);
            $addr->country = Country::normalize($addr->country);
            $req->addresses[] = $addr;
        }

        foreach ($this->strings('cryptoAddress', 'cryptoAddresses') as $input) {
            $parts = explode(':', $input);
            if (count($parts) === 2) {
                $req->cryptoAddresses[] = new CryptoAddress(Go::upper($parts[0]), $parts[1]);
            }
        }

        return $req->normalize();
    }

    /** @return list<GovernmentID> */
    private function governmentIDs(): array
    {
        $out = [];
        foreach ($this->withPrefix('gov_') as $key) {
            $parts = explode(':', $this->get($key));
            if (count($parts) <= 1) {
                continue;
            }
            $out[] = new GovernmentID(substr($key, 4), $parts[0], $parts[1]);
        }

        return $out;
    }

    /** @return list<string> */
    private function strings(string ...$names): array
    {
        $out = [];
        foreach ($names as $n) {
            foreach ($this->getAll($n) as $v) {
                $out[] = Go::trimSpace($v);
            }
        }

        return $out;
    }

    public static function readDate(string $input): ?\DateTimeImmutable
    {
        return $input === '' ? null : GoTime::parseAny(self::DATE_LAYOUTS, $input);
    }

    private static function readInt(string $input, string $context): int
    {
        if (!preg_match('/^[+-]?[0-9]+$/', $input) || abs((int) $input) > 524287) {
            throw new InvalidArgumentException("$context: invalid integer");
        }

        return (int) $input;
    }

    /**
     * Search options from the same parameters (limit, minMatch, debug, algorithm, requestID, debugSourceIDs).
     */
    public function options(int $defaultLimit = 10, int $maxLimit = 100): SearchOptions
    {
        // Watchman returns at most 100 hits; limit <= 0 or garbage falls back to the default.
        $maxLimit = max(1, min($maxLimit, 100));
        $opts = new SearchOptions();
        $opts->limit = max(1, min($defaultLimit, $maxLimit));
        if (($v = $this->get('limit')) !== '') {
            $n = preg_match('/^[+-]?[0-9]+$/', $v) ? (int) $v : 0;
            if ($n > 0) {
                $opts->limit = $n;
            }
        }
        $opts->limit = min($opts->limit, $maxLimit);
        if (($v = $this->get('minMatch')) !== '') {
            $opts->minMatch = is_numeric($v) ? (float) $v : 0.0;
        }
        $opts->requestId = $this->get('requestID');
        [$opts->debug, $opts->debugMinMatch] = self::parseDebug($this->get('debug'));
        $opts->debugSourceIds = explode(',', $this->get('debugSourceIDs'));
        $opts->algorithm = Algorithm::parse($this->get('algorithm'));

        return $opts;
    }

    /** @return array{0:bool,1:float} */
    public static function parseDebug(string $raw): array
    {
        $raw = Go::trimSpace($raw);
        if ($raw === '') {
            return [false, 0.0];
        }
        if (str_contains($raw, '.')) {
            if (!is_numeric($raw) || is_nan((float) $raw) || is_infinite((float) $raw)) {
                throw new InvalidArgumentException('invalid debug threshold');
            }
            $n = (float) $raw;
            if ($n < 0 || $n > 1) {
                throw new InvalidArgumentException('debug threshold must be between 0.0 and 1.0');
            }

            return [true, $n];
        }
        if (Go::yes($raw)) {
            return [true, 0.0];
        }
        if (in_array(strtolower($raw), ['0', 'f', 'false', 'n', 'no', 'off'], true)) {
            return [false, 0.0];
        }

        throw new InvalidArgumentException('invalid debug value');
    }
}
