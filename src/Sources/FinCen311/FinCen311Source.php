<?php

declare(strict_types=1);

namespace Watchman\Sources\FinCen311;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use Watchman\Download\Downloader;
use Watchman\Download\ListResult;
use Watchman\Download\ListSource;
use Watchman\Search\Business;
use Watchman\Search\ContactInfo;
use Watchman\Search\Entity;
use Watchman\Search\EntityType;
use Watchman\Search\Organization;
use Watchman\Search\SanctionsInfo;
use Watchman\Search\SourceList;
use Watchman\Support\Go;

/**
 * FinCEN Section 311 / 9714 special measures (us_fincen_311). Port of pkg/sources/fincen_311.
 * The list is scraped from an HTML table on fincen.gov.
 */
final class FinCen311Source implements ListSource
{
    public const DEFAULT_URL = 'https://www.fincen.gov/resources/statutes-and-regulations/311-and-9714-special-measures';

    public function __construct(private ?string $url = null)
    {
    }

    public function name(): string
    {
        return SourceList::US_FINCEN_311;
    }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        $url = $this->url ?: Go::strxOr((string) getenv('FINCEN_311_DOWNLOAD_URL'), self::DEFAULT_URL);
        $files = $downloader->getFiles($initialDir, ['fincen_311.html' => $url]);
        if (!isset($files['fincen_311.html'])) {
            throw new RuntimeException('unexpected 0 FinCEN 311 files found');
        }
        $html = (string) file_get_contents($files['fincen_311.html']);
        $measures = self::parse($html);

        return new ListResult($this->name(), array_map([self::class, 'toEntity'], $measures), hash('sha256', $html));
    }

    /** @return list<array<string,mixed>> */
    public static function parse(string $html): array
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        // HTML5 parsers (Go's x/net/html) wrap bare <tr> in an implicit <tbody>; libxml does not,
        // so the first query is expressed as "rows not in thead/tfoot".
        $queries = [
            '//table//tr[not(ancestor::thead) and not(ancestor::tfoot)]',
            '//table//tr[position()>1]',
            "//div[contains(@class, 'table')]//tr",
            '//article//table//tr',
        ];
        $rows = [];
        foreach ($queries as $q) {
            $res = $xp->query($q);
            if ($res !== false && $res->length > 0) {
                $rows = iterator_to_array($res);
                break;
            }
        }
        if ($rows === []) {
            throw new RuntimeException('no table rows found - HTML structure may have changed');
        }
        $measures = [];
        foreach ($rows as $row) {
            $m = self::parseRow($xp, $row);
            if ($m === null) {
                continue;
            }
            if ($m['entityName'] !== '' && !Go::equalFold($m['entityName'], 'Entity Name') && !Go::equalFold($m['entityName'], 'Name')) {
                $measures[] = $m;
            }
        }
        if ($measures === []) {
            throw new RuntimeException('no valid entities parsed from HTML');
        }

        return $measures;
    }

    private static function parseRow(DOMXPath $xp, DOMNode $row): ?array
    {
        $cells = iterator_to_array($xp->query('.//td', $row) ?: []);
        if (count($cells) < 2) {
            return null;
        }
        $m = [
            'entityName' => self::cleanText($cells[0]->textContent), 'entityType' => '',
            'findingUrl' => '', 'findingDate' => '', 'nprmUrl' => '', 'nprmDate' => '',
            'finalRuleUrl' => '', 'finalRuleDate' => '', 'rescindedUrl' => '', 'rescindedDate' => '', 'isRescinded' => false,
        ];
        [$m['findingUrl'], $m['findingDate']] = self::linkAndDate($xp, $cells[1]);
        if (count($cells) > 2) {
            [$m['nprmUrl'], $m['nprmDate']] = self::linkAndDate($xp, $cells[2]);
        }
        if (count($cells) > 3) {
            [$m['finalRuleUrl'], $m['finalRuleDate']] = self::linkAndDate($xp, $cells[3]);
        }
        if (count($cells) > 4) {
            [$m['rescindedUrl'], $m['rescindedDate']] = self::linkAndDate($xp, $cells[4]);
            $text = Go::lower($cells[4]->textContent);
            $m['isRescinded'] = $m['rescindedUrl'] !== '' || str_contains($text, 'rescind') || ($m['rescindedDate'] !== '' && $m['rescindedDate'] !== '---');
        }
        $m['entityType'] = self::classify($m['entityName']);

        return $m;
    }

    /** @return array{0:string,1:string} */
    private static function linkAndDate(DOMXPath $xp, DOMNode $cell): array
    {
        $url = '';
        foreach ($xp->query('.//a', $cell) ?: [] as $a) {
            if ($a instanceof DOMElement && $a->hasAttribute('href') && $url === '') {
                $url = $a->getAttribute('href');
                if (str_starts_with($url, '/')) {
                    $url = 'https://www.fincen.gov' . $url;
                }
                break;
            }
        }
        $text = self::cleanText($cell->textContent);
        $date = ($text !== '' && $text !== '---' && $text !== '-') ? $text : '';

        return [$url, $date];
    }

    public static function classify(string $name): string
    {
        $lower = Go::lower($name);
        foreach (['bank', 'credit union', 'financial institution', 'fbme', 'ablv', 'banca privada', 'asiauniversalbank',
            'bitzlato', 'suex', 'garantex', 'casa de cambio', 'pm2btc', 'liberty reserve', 'delta asia', 'casa de bolsa',
            "institución de banca"] as $k) {
            if (str_contains($lower, $k)) {
                return 'financial_institution';
            }
        }
        foreach (['iran', 'korea', 'burma', 'myanmar', 'lebanon', 'venezuela', 'republic of', 'nauru', 'ukraine',
            "people's republic", 'democratic republic', 'islamic republic'] as $k) {
            if (str_contains($lower, $k)) {
                return 'jurisdiction';
            }
        }
        foreach (['virtual currency', 'mixing', 'convertible', 'transaction', 'class of', 'gambling'] as $k) {
            if (str_contains($lower, $k)) {
                return 'transaction_class';
            }
        }

        return 'financial_institution';
    }

    private static function cleanText(string $s): string
    {
        $s = Go::trimSpace($s);
        $s = str_replace(["\n", "\t"], ' ', $s);
        while (str_contains($s, '  ')) {
            $s = str_replace('  ', ' ', $s);
        }

        return $s;
    }

    public static function toEntity(array $sm): Entity
    {
        $id = Go::lower($sm['entityName']);
        $id = str_replace([' ', "'", ',', '.', '(', ')'], ['_', '', '', '', '', ''], $id);
        $e = new Entity($sm['entityName'], '', SourceList::US_FINCEN_311, 'fincen311_' . $id);
        $e->sourceData = array_filter($sm, static fn ($v, $k) => $k === 'isRescinded' || $k === 'entityName' || $k === 'entityType' || $v !== '', ARRAY_FILTER_USE_BOTH);
        if ($sm['entityType'] === 'jurisdiction' || $sm['entityType'] === 'transaction_class') {
            $e->type = EntityType::ORGANIZATION;
            $e->organization = new Organization($sm['entityName']);
        } else {
            $e->type = EntityType::BUSINESS;
            $e->business = new Business($sm['entityName']);
        }
        $sites = array_values(array_filter([$sm['findingUrl'], $sm['nprmUrl'], $sm['finalRuleUrl'], $sm['rescindedUrl']], static fn ($v) => $v !== ''));
        $e->contact = new ContactInfo(websites: $sites);

        $programs = ['FinCEN Special Measures'];
        $programs[] = $sm['entityType'] === 'transaction_class' ? 'Section 9714' : 'Section 311';
        $desc = ['FinCEN Special Measures Target'];
        if ($sm['findingDate'] !== '') {
            $desc[] = 'Finding: ' . $sm['findingDate'];
        }
        if ($sm['finalRuleDate'] !== '') {
            $desc[] = 'Final Rule: ' . $sm['finalRuleDate'];
        }
        if ($sm['isRescinded']) {
            $desc[] = 'Status: RESCINDED' . ($sm['rescindedDate'] !== '' ? ' (' . $sm['rescindedDate'] . ')' : '');
        } else {
            $desc[] = 'Status: ACTIVE';
        }
        $e->sanctionsInfo = new SanctionsInfo($programs, false, implode('. ', $desc) . '.');

        return $e->normalize();
    }
}
