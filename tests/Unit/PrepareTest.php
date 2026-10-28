<?php

declare(strict_types=1);

namespace Watchman\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Watchman\Prepare\ExNames;
use Watchman\Prepare\LegalForms;
use Watchman\Prepare\Normalize;
use Watchman\Prepare\PartyLabels;
use Watchman\Prepare\Reorder;
use Watchman\Prepare\VesselPlace;
use Watchman\Prepare\VesselPrefixes;

/**
 * Expectations ported from internal/prepare/*_test.go.
 */
final class PrepareTest extends TestCase
{
    public function testSplitExNames(): void
    {
        foreach ([
            ['Ocean Pioneer (ex-Cape Diamond)', 'Ocean Pioneer', ['Cape Diamond']],
            ['Star Voyager (ex-Southern Glory)', 'Star Voyager', ['Southern Glory']],
            ['M/T Ondara Leigh (ex-Pelican Morrow)', 'M/T Ondara Leigh', ['Pelican Morrow']],
            ['Wheatland Glory (ex-Pacific Sower)', 'Wheatland Glory', ['Pacific Sower']],
            ['M.T. THONG NAWA 3 (Laem Chabang)', 'M.T. THONG NAWA 3 (Laem Chabang)', []],
            ['Solenne Harbour', 'Solenne Harbour', []],
            ['', '', []],
            ['Foo (ex-Bar) (ex-Baz)', 'Foo', ['Bar', 'Baz']],
            ['Foo (EX Cape Diamond)', 'Foo', ['Cape Diamond']],
            ['Ocean Pioneer (example)', 'Ocean Pioneer (example)', []],
            ['Star Voyager (ex)', 'Star Voyager (ex)', []],
            ['M/T Rosalind Meadow (f.k.a. Meadow Spirit)', 'M/T Rosalind Meadow', ['Meadow Spirit']],
            ['Nordbryggen Marine Coatings AS (f.k.a. Bryggen Paint AS)', 'Nordbryggen Marine Coatings AS', ['Bryggen Paint AS']],
            ['Ostrowski Grain Partners LLC (f.k.a. Ostrowski Feed & Grain LLC)', 'Ostrowski Grain Partners LLC', ['Ostrowski Feed & Grain LLC']],
            ['BEIRUT CEDAR TRADING SAL, f.k.a. LEBANON CEDAR IMPORT EXPORT SAL', 'BEIRUT CEDAR TRADING SAL', ['LEBANON CEDAR IMPORT EXPORT SAL']],
            ['Dalgleish Rope Works Ltd, formerly Dalgleish & Muir Ropes Ltd', 'Dalgleish Rope Works Ltd', ['Dalgleish & Muir Ropes Ltd']],
            ['Olmsbury Grain Corporation f/k/a Olmsbury Milling Corporation', 'Olmsbury Grain Corporation', ['Olmsbury Milling Corporation']],
            ['Kenari Food Distribution Sdn. Bhd. (formerly known as Kenari Frozen Foods Sdn. Bhd.)', 'Kenari Food Distribution Sdn. Bhd.', ['Kenari Frozen Foods Sdn. Bhd.']],
            ['Tamarind Crest Commodities Pte. Ltd. (formerly Tamarind Crest Resources Pte. Ltd.)', 'Tamarind Crest Commodities Pte. Ltd.', ['Tamarind Crest Resources Pte. Ltd.']],
            ['MV Karachi Crescent (ex Mandvi Bay, ex Wanshan Pearl)', 'MV Karachi Crescent', ['Mandvi Bay', 'Wanshan Pearl']],
            ['MV Stamatia Horizon (ex-Elenitsa Z, ex-Aegean Lynx)', 'MV Stamatia Horizon', ['Elenitsa Z', 'Aegean Lynx']],
            ['Superior Harvester (ex Duluth Harvester, ex Iron Range Harvester)', 'Superior Harvester', ['Duluth Harvester', 'Iron Range Harvester']],
            ['Theodosia K (ex-Lindos Harrier until 2019)', 'Theodosia K', ['Lindos Harrier']],
            ['MV Apapa Falcon (ex Warri Osprey, 2020)', 'MV Apapa Falcon', ['Warri Osprey']],
            ['Kalliopi Dawn ex Orion Tanager', 'Kalliopi Dawn', ['Orion Tanager']],
            ['COASTAL DEFENDER EX ATLA', 'COASTAL DEFENDER', ['ATLA']],
            ['MT QUINTARA SPIRIT EX PALOMERA', 'MT QUINTARA SPIRIT', ['PALOMERA']],
            ['Qamar Al Khaleej ex-Rimal Star', 'Qamar Al Khaleej', ['Rimal Star']],
            ['Export Trading Ltd', 'Export Trading Ltd', []],
            ['Example Holdings', 'Example Holdings', []],
        ] as [$in, $cleaned, $former]) {
            $this->assertSame([$cleaned, $former], ExNames::split($in), $in);
        }
    }

    public function testLowerAndRemovePunctuation(): void
    {
        $this->assertSame('nicolas maduro', Normalize::lowerAndRemovePunctuation('Nicolás MADURO'));
        $this->assertSame('11 420 2 1 corp', Normalize::lowerAndRemovePunctuation('11,420.2-1 CORP.'));
        $this->assertSame('a i c sogo kenkyusho', Normalize::lowerAndRemovePunctuation('A.I.C. SOGO KENKYUSHO'));
    }

    public function testReorderSdnName(): void
    {
        $this->assertSame('Nicolas MADURO MOROS', Reorder::sdnName('MADURO MOROS, Nicolas', 'individual'));
        $this->assertSame("Shibl Muhsin 'Ubayd AL-ZAYDI", Reorder::sdnName("AL-ZAYDI, Shibl Muhsin 'Ubayd", 'Individual'));
        $this->assertSame('MADURO MOROS, Nicolas', Reorder::sdnName('MADURO MOROS, Nicolas', 'entity'));
    }

    public function testLegalFormsAndLabels(): void
    {
        $this->assertSame('harrowfield bearings ltd', LegalForms::canonicalize('harrowfield bearings limited'));
        $this->assertSame('acme llc', LegalForms::canonicalize('acme l l c'));
        $this->assertSame('acme llc', LegalForms::canonicalize('acme limited liability company'));
        $this->assertSame('TRANSPORTES REFRIGERADOS DEL SURESTE SA DE CV', PartyLabels::strip('SHIPPER: TRANSPORTES REFRIGERADOS DEL SURESTE SA DE CV'));
        $this->assertSame('MV SIAM ORCHID 7', VesselPlace::stripTrailing('MV SIAM ORCHID 7, Bangkok'));
        $this->assertSame('siam orchid 7', VesselPrefixes::strip('m v siam orchid 7'));
    }
}
