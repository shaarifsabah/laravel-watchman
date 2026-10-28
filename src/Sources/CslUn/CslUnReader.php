<?php

declare(strict_types=1);

namespace Watchman\Sources\CslUn;

use DOMElement;
use DOMText;
use RuntimeException;
use XMLReader;

/**
 * Port of pkg/sources/csl_un/reader_un.go (+ the encoding/xml struct mapping in un_csl.go).
 *
 * Streams the XML and, for every <INDIVIDUAL> / <ENTITY> element (matched by local name,
 * anywhere in the document, like Go's token loop), decodes it the way encoding/xml would:
 * string fields take the direct character data of the matching child element (last one
 * wins), slice fields collect every matching child. Records are associative arrays keyed
 * by the Go struct field names (which is also what encoding/json emits — no json tags).
 */
final class CslUnReader
{
    /**
     * @param string $path file path, or the raw XML document when $isXml is true
     * @param callable(array<string,mixed>):void $onIndividual
     * @param callable(array<string,mixed>):void $onEntity
     */
    public static function read(string $path, callable $onIndividual, callable $onEntity, bool $isXml = false): void
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $xml = new XMLReader();
        try {
            $opened = $isXml
                ? @$xml->XML($path, null, LIBXML_NONET | LIBXML_PARSEHUGE)
                : @$xml->open($path, null, LIBXML_NONET | LIBXML_PARSEHUGE);
            if (!$opened) {
                throw new RuntimeException('unable to open UN consolidated list XML');
            }
            $more = @$xml->read();
            while ($more) {
                if ($xml->nodeType === XMLReader::ELEMENT && ($xml->localName === 'INDIVIDUAL' || $xml->localName === 'ENTITY')) {
                    $name = $xml->localName;
                    $node = @$xml->expand();
                    if ($node instanceof DOMElement && !self::hasError()) {
                        if ($name === 'INDIVIDUAL') {
                            $onIndividual(self::decodeIndividual($node));
                        } else {
                            $onEntity(self::decodeEntity($node));
                        }
                    }
                    $more = @$xml->next();

                    continue;
                }
                $more = @$xml->read();
            }
            if (($err = self::firstError()) !== null) {
                throw new RuntimeException('XML syntax error: ' . $err);
            }
        } finally {
            $xml->close();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private static function hasError(): bool
    {
        return self::firstError() !== null;
    }

    private static function firstError(): ?string
    {
        foreach (libxml_get_errors() as $e) {
            if ($e->level >= LIBXML_ERR_ERROR) {
                return trim($e->message) . " (line {$e->line})";
            }
        }

        return null;
    }

    /** @return array<string,mixed> UNIndividual */
    public static function decodeIndividual(DOMElement $el): array
    {
        return [
            'DataID' => self::field($el, 'DATAID'),
            'FirstName' => self::field($el, 'FIRST_NAME'),
            'SecondName' => self::field($el, 'SECOND_NAME'),
            'ThirdName' => self::field($el, 'THIRD_NAME'),
            'FourthName' => self::field($el, 'FOURTH_NAME'),
            'UNListType' => self::field($el, 'UN_LIST_TYPE'),
            'ReferenceNumber' => self::field($el, 'REFERENCE_NUMBER'),
            'ListedOn' => self::field($el, 'LISTED_ON'),
            'Gender' => self::field($el, 'GENDER'),
            'Comments' => self::field($el, 'COMMENTS1'),
            'Nationalities' => self::structs($el, 'NATIONALITY', ['Text' => 'VALUE']),
            'Aliases' => self::structs($el, 'INDIVIDUAL_ALIAS', self::ALIAS),
            'Addresses' => self::structs($el, 'INDIVIDUAL_ADDRESS', self::ADDRESS),
            'BirthDates' => self::structs($el, 'INDIVIDUAL_DATE_OF_BIRTH', ['Type' => 'TYPE_OF_DATE', 'Date' => 'DATE', 'Year' => 'YEAR']),
            'BirthPlaces' => self::structs($el, 'INDIVIDUAL_PLACE_OF_BIRTH', ['City' => 'CITY', 'State' => 'STATE_PROVINCE', 'Country' => 'COUNTRY']),
        ];
    }

    /** @return array<string,mixed> UNEntity */
    public static function decodeEntity(DOMElement $el): array
    {
        return [
            'DataID' => self::field($el, 'DATAID'),
            'FirstName' => self::field($el, 'FIRST_NAME'),
            'UNListType' => self::field($el, 'UN_LIST_TYPE'),
            'ReferenceNumber' => self::field($el, 'REFERENCE_NUMBER'),
            'ListedOn' => self::field($el, 'LISTED_ON'),
            'Comments' => self::field($el, 'COMMENTS1'),
            'Aliases' => self::structs($el, 'ENTITY_ALIAS', self::ALIAS),
            'Addresses' => self::structs($el, 'ENTITY_ADDRESS', self::ADDRESS),
        ];
    }

    private const ALIAS = ['Quality' => 'QUALITY', 'Name' => 'ALIAS_NAME'];
    private const ADDRESS = ['Street' => 'STREET', 'City' => 'CITY', 'StateProvince' => 'STATE_PROVINCE', 'Country' => 'COUNTRY', 'Note' => 'NOTE'];

    /** Direct-child string field: last matching child's direct character data (encoding/xml semantics). */
    private static function field(DOMElement $el, string $tag): string
    {
        $value = '';
        for ($c = $el->firstChild; $c !== null; $c = $c->nextSibling) {
            if ($c instanceof DOMElement && $c->localName === $tag) {
                $value = self::chardata($c);
            }
        }

        return $value;
    }

    /**
     * @param array<string,string> $fields Go field => xml tag
     * @return list<array<string,string>>
     */
    private static function structs(DOMElement $el, string $tag, array $fields): array
    {
        $out = [];
        for ($c = $el->firstChild; $c !== null; $c = $c->nextSibling) {
            if ($c instanceof DOMElement && $c->localName === $tag) {
                $s = [];
                foreach ($fields as $goName => $xmlTag) {
                    $s[$goName] = self::field($c, $xmlTag);
                }
                $out[] = $s;
            }
        }

        return $out;
    }

    private static function chardata(DOMElement $el): string
    {
        $s = '';
        for ($c = $el->firstChild; $c !== null; $c = $c->nextSibling) {
            if ($c instanceof DOMText) { // text and CDATA, not nested elements
                $s .= $c->data;
            }
        }

        return $s;
    }
}
