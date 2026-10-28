<?php

declare(strict_types=1);

namespace Watchman\Search;

/**
 * Precomputed, normalized fields used by the similarity scorer.
 * Mirrors pkg/search.PreparedFields.
 */
final class PreparedFields
{
    public string $name = '';
    /** @var list<string> */
    public array $altNames = [];
    /** @var list<string> */
    public array $nameFields = [];
    /** @var list<list<string>> */
    public array $altNameFields = [];
    /** @var list<string> */
    public array $historicalNames = [];
    /** @var list<list<string>> */
    public array $historicalNameFields = [];
    /** @var list<float> */
    public array $nameWeights = [];
    /** @var list<list<float>> */
    public array $altNameWeights = [];
    /** @var list<list<float>> */
    public array $historicalNameWeights = [];
    public ContactInfo $contact;
    /** @var list<PreparedAddress> */
    public array $addresses = [];
    /** @var list<string> */
    public array $domains = [];

    public function __construct()
    {
        $this->contact = new ContactInfo();
    }

    public function __clone()
    {
        $this->contact = clone $this->contact;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'altNames' => $this->altNames,
            'nameFields' => $this->nameFields,
            'altNameFields' => $this->altNameFields,
            'historicalNames' => $this->historicalNames,
            'historicalNameFields' => $this->historicalNameFields,
            'nameWeights' => $this->nameWeights,
            'altNameWeights' => $this->altNameWeights,
            'historicalNameWeights' => $this->historicalNameWeights,
            'contact' => [
                'phoneNumbers' => $this->contact->phoneNumbers,
                'faxNumbers' => $this->contact->faxNumbers,
                'websites' => $this->contact->websites,
            ],
            'addresses' => array_map(static fn (PreparedAddress $a) => $a->toArray(), $this->addresses),
            'domains' => $this->domains,
        ];
    }

    public static function fromArray(array $a): self
    {
        $p = new self();
        $p->name = (string) ($a['name'] ?? '');
        $p->altNames = $a['altNames'] ?? [];
        $p->nameFields = $a['nameFields'] ?? [];
        $p->altNameFields = $a['altNameFields'] ?? [];
        $p->historicalNames = $a['historicalNames'] ?? [];
        $p->historicalNameFields = $a['historicalNameFields'] ?? [];
        $p->nameWeights = $a['nameWeights'] ?? [];
        $p->altNameWeights = $a['altNameWeights'] ?? [];
        $p->historicalNameWeights = $a['historicalNameWeights'] ?? [];
        $c = $a['contact'] ?? [];
        $p->contact = new ContactInfo([], $c['phoneNumbers'] ?? [], $c['faxNumbers'] ?? [], $c['websites'] ?? []);
        $p->addresses = array_map(static fn ($x) => PreparedAddress::fromArray($x), $a['addresses'] ?? []);
        $p->domains = $a['domains'] ?? [];

        return $p;
    }
}
