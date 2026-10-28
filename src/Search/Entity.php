<?php

declare(strict_types=1);

namespace Watchman\Search;

use JsonSerializable;
use Watchman\Norm\Country;
use Watchman\Norm\Domain;
use Watchman\Norm\Phone;
use Watchman\Norm\Website;
use Watchman\Prepare\ExNames;
use Watchman\Prepare\Normalize;
use Watchman\Prepare\Stopwords;
use Watchman\Support\Go;

/**
 * A searchable record (list entry or query). Mirrors pkg/search.Entity.
 */
class Entity implements JsonSerializable
{
    public string $name = '';
    public string $type = '';
    public string $source = '';
    public string $sourceId = '';

    public ?Person $person = null;
    public ?Business $business = null;
    public ?Organization $organization = null;
    public ?Aircraft $aircraft = null;
    public ?Vessel $vessel = null;

    public ContactInfo $contact;
    /** @var list<Address> */
    public array $addresses = [];
    /** @var list<CryptoAddress> */
    public array $cryptoAddresses = [];
    /** @var list<Affiliation> */
    public array $affiliations = [];
    public ?SanctionsInfo $sanctionsInfo = null;
    /** @var list<HistoricalInfo> */
    public array $historicalInfo = [];

    public PreparedFields $preparedFields;

    /** Original list record (associative array with the source list's field names). */
    public mixed $sourceData = null;

    public function __construct(string $name = '', string $type = '', string $source = '', string $sourceId = '')
    {
        $this->name = $name;
        $this->type = $type;
        $this->source = $source;
        $this->sourceId = $sourceId;
        $this->contact = new ContactInfo();
        $this->preparedFields = new PreparedFields();
    }

    public function __clone()
    {
        $this->contact = clone $this->contact;
        $this->preparedFields = clone $this->preparedFields;
        foreach (['person', 'business', 'organization', 'aircraft', 'vessel', 'sanctionsInfo'] as $p) {
            if ($this->{$p} !== null) {
                $this->{$p} = clone $this->{$p};
            }
        }
    }

    /**
     * Compute PreparedFields. Returns a new (normalized) entity like Go's value receiver.
     */
    public function normalize(): static
    {
        $e = clone $this;
        $pf = new PreparedFields();

        $pf->name = self::prepareEntityName($e->name, $e->type);
        $pf->nameFields = self::removeStopwords($pf->name);
        $extraFormer = ExNames::exNames($e->name);

        foreach (['person', 'business', 'organization', 'aircraft', 'vessel'] as $p) {
            if ($e->{$p} !== null) {
                $pf->altNames = self::normalizeNames($e->{$p}->altNames, $e->type);
            }
        }

        foreach ($pf->altNames as $alt) {
            $pf->altNameFields[] = self::removeStopwords($alt);
        }

        foreach ($e->historicalInfo as $hist) {
            if (!Go::equalFold($hist->type, 'Former Name') || $hist->value === '') {
                continue;
            }
            $prepared = self::prepareEntityName($hist->value, $e->type);
            $pf->historicalNames[] = $prepared;
            $pf->historicalNameFields[] = self::removeStopwords($prepared);
        }
        foreach ($extraFormer as $former) {
            $prepared = self::prepareEntityName($former, $e->type);
            if ($prepared === '') {
                continue;
            }
            $pf->historicalNames[] = $prepared;
            $pf->historicalNameFields[] = self::removeStopwords($prepared);
        }

        $pf->contact->phoneNumbers = array_map([Phone::class, 'normalize'], $e->contact->phoneNumbers);
        $pf->contact->faxNumbers = array_map([Phone::class, 'normalize'], $e->contact->faxNumbers);
        $pf->contact->websites = self::normalizeWebsites($e->contact->websites);
        $pf->domains = self::normalizeDomains($e->contact->domains, $e->contact->websites, $e->contact->emailAddresses);

        $pf->addresses = array_map([self::class, 'normalizeAddress'], $e->addresses);

        $e->preparedFields = $pf;

        return $e;
    }

    /** @return list<string> */
    public static function removeStopwords(string $input): array
    {
        if ($input === '') {
            return [];
        }

        return Go::fields(Stopwords::remove($input));
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    public static function normalizeNames(array $names, string $type): array
    {
        return array_values(array_map(static fn (string $n) => self::prepareEntityName($n, $type), $names));
    }

    public static function prepareEntityName(string $name, string $type): string
    {
        $name = \Watchman\Prepare\PartyLabels::strip($name);
        [$name] = ExNames::split($name);
        if ($type === EntityType::VESSEL) {
            $name = \Watchman\Prepare\VesselPlace::stripTrailing($name);
        }
        $out = Normalize::lowerAndRemovePunctuation($name);
        if ($type === EntityType::VESSEL) {
            $out = \Watchman\Prepare\VesselPrefixes::strip($out);
        }
        if ($type === EntityType::BUSINESS || $type === EntityType::ORGANIZATION) {
            $out = \Watchman\Prepare\LegalForms::canonicalize($out);
        }

        return $out;
    }

    /**
     * @param list<string> $sites
     * @return list<string>
     */
    private static function normalizeWebsites(array $sites): array
    {
        $out = [];
        foreach ($sites as $site) {
            $n = Website::normalize($site);
            if ($n === '' || in_array($n, $out, true)) {
                continue;
            }
            $out[] = $n;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function normalizeDomains(array $domains, array $websites, array $emails): array
    {
        $out = [];
        $seen = [];
        $add = static function (array $keys) use (&$out, &$seen): void {
            foreach ($keys as $key) {
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $key;
            }
        };
        foreach ($domains as $d) {
            $add(Domain::keys($d));
        }
        foreach ($websites as $site) {
            $add(Domain::keys($site));
        }
        foreach ($emails as $email) {
            $add(Domain::keysFromEmail($email));
        }

        return $out;
    }

    public static function normalizeAddress(Address $addr): PreparedAddress
    {
        $out = new PreparedAddress(
            line1: str_replace(',', '', Go::lower($addr->line1)),
            line2: str_replace(',', '', Go::lower($addr->line2)),
            city: Go::lower($addr->city),
            postalCode: Go::lower($addr->postalCode),
            state: Go::lower($addr->state),
            country: Go::lower(Country::normalize($addr->country)),
        );
        if ($out->line1 !== '') {
            $out->line1Fields = Go::fields($out->line1);
        }
        if ($out->line2 !== '') {
            $out->line2Fields = Go::fields($out->line2);
        }
        if ($out->city !== '') {
            $out->cityFields = Go::fields($out->city);
        }

        return $out;
    }

    /** Government IDs from the person/business/organization record. */
    public function governmentIDs(): array
    {
        if ($this->person !== null && $this->person->governmentIDs !== []) {
            return $this->person->governmentIDs;
        }
        if ($this->business !== null && $this->business->governmentIDs !== []) {
            return $this->business->governmentIDs;
        }
        if ($this->organization !== null && $this->organization->governmentIDs !== []) {
            return $this->organization->governmentIDs;
        }

        return [];
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'entityType' => $this->type,
            'sourceList' => $this->source,
            'sourceID' => $this->sourceId,
            'person' => $this->person?->toArray(),
            'business' => $this->business?->toArray(),
            'organization' => $this->organization?->toArray(),
            'aircraft' => $this->aircraft?->toArray(),
            'vessel' => $this->vessel?->toArray(),
            'contact' => $this->contact->toArray(),
            'addresses' => Lists::arrays($this->addresses),
            'cryptoAddresses' => Lists::arrays($this->cryptoAddresses),
            'affiliations' => Lists::arrays($this->affiliations),
            'sanctionsInfo' => $this->sanctionsInfo?->toArray(),
            'historicalInfo' => Lists::arrays($this->historicalInfo),
            'sourceData' => $this->sourceData,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Build from watchman's JSON representation (as returned by the Go server or toArray()).
     */
    public static function fromArray(array $a): static
    {
        $e = new static(
            (string) ($a['name'] ?? ''),
            (string) ($a['entityType'] ?? ''),
            (string) ($a['sourceList'] ?? ''),
            (string) ($a['sourceID'] ?? ''),
        );
        $e->person = isset($a['person']) && is_array($a['person']) ? Person::fromArray($a['person']) : null;
        $e->business = isset($a['business']) && is_array($a['business']) ? Business::fromArray($a['business']) : null;
        $e->organization = isset($a['organization']) && is_array($a['organization']) ? Organization::fromArray($a['organization']) : null;
        $e->aircraft = isset($a['aircraft']) && is_array($a['aircraft']) ? Aircraft::fromArray($a['aircraft']) : null;
        $e->vessel = isset($a['vessel']) && is_array($a['vessel']) ? Vessel::fromArray($a['vessel']) : null;
        $e->contact = ContactInfo::fromArray((array) ($a['contact'] ?? []));
        $e->addresses = array_values(array_map(static fn ($x) => Address::fromArray((array) $x), (array) ($a['addresses'] ?? [])));
        $e->cryptoAddresses = array_values(array_map(static fn ($x) => CryptoAddress::fromArray((array) $x), (array) ($a['cryptoAddresses'] ?? [])));
        $e->affiliations = array_values(array_map(static fn ($x) => Affiliation::fromArray((array) $x), (array) ($a['affiliations'] ?? [])));
        $e->sanctionsInfo = isset($a['sanctionsInfo']) && is_array($a['sanctionsInfo']) ? SanctionsInfo::fromArray($a['sanctionsInfo']) : null;
        $e->historicalInfo = array_values(array_map(static fn ($x) => HistoricalInfo::fromArray((array) $x), (array) ($a['historicalInfo'] ?? [])));
        $e->sourceData = $a['sourceData'] ?? null;

        return $e;
    }
}
