<?php

declare(strict_types=1);

namespace Watchman\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Watchman\Prepare\Normalize as N;
use Watchman\StringScore\JaroWinkler;
use Watchman\StringScore\Params;
use Watchman\Support\Go;

/**
 * Expectations ported from internal/stringscore/jaro_winkler_test.go (TestJaroWinkler).
 */
final class JaroWinklerTest extends TestCase
{
    protected function setUp(): void
    {
        Params::set(Params::fromEnvironment());
    }

    public static function cases(): array
    {
        return [
		
		['wei, zhao', 'wei, Zhao', 0.917],
		['WEI, Zhao', 'WEI, Zhao', 1.0],
		['WEI Zhao', 'WEI Zhao', 1.0],
		[strtolower('WEI Zhao'), N::lowerAndRemovePunctuation('WEI, Zhao'), 1.0],

		
		['jane doe', 'jan lahore', 0.439],
		['jan lahore', 'jane doe', 0.549],

		
		['john doe', 'paul john', 0.624],
		['john doe', 'john othername', 0.440],
		['tai me', 'taim', 0.819],
		['elvin', 'elvis', 0.920],

		
		['jane doe', 'jane doe2', 0.940],

		
		['kalamity linden', 'kala limited', 0.687],
		['kala limited', 'kalamity linden', 0.687],

		
		['nicolas', 'nicolas', 1.0],
		['nicolas moros maduro', 'nicolas maduro', 0.958],
		['nicolas maduro', 'nicolas moros maduro', 0.839],

		
		['ian', 'ian mckinley', 0.429],
		['iap', 'ian mckinley', 0.352],
		['ian mckinley', 'ian', 0.891],
		['ian mckinley', 'iap', 0.733],
		['ian mckinley', 'tian xiang 7', 0.000],
		['bindaree food group pty', N::lowerAndRemovePunctuation('independent insurance group ltd'), 0.269], 
		['bindaree food group pty ltd', 'independent insurance group ltd', 0.401],                                
		['p.c.c. (singapore) private limited', 'culver max entertainment private limited', 0.514],
		['zincum llc', 'easy verification inc.', 0.000],
		['transpetrochart co ltd', 'jx metals trading co.', 0.431],
		['technolab', 'moomoo technologies inc', 0.565],
		['sewa security services', 'sesa - safety & environmental services australia pty ltd', 0.480],
		['bueno', '20/f rykadan capital twr135 hoi bun rd, kwun tong 135 hoi bun rd., kwun tong', 0.094],

		
		['nicolas maduro', 'nicolás maduro', 0.937],
		['nicolas maduro', N::lowerAndRemovePunctuation('nicolás maduro'), 1.0],
		['nic maduro', 'nicolas maduro', 0.872],
		['nick maduro', 'nicolas maduro', 0.859],
		['nicolas maduroo', 'nicolas maduro', 0.966],
		['nicolas maduro', 'nicolas maduro', 1.0],
		['maduro, nicolas', 'maduro, nicolas', 1.0],
		['maduro moros, nicolas', 'maduro moros, nicolas', 1.0],
		['maduro moros, nicolas', 'nicolas maduro', 0.953],
		['nicolas maduro moros', 'maduro', 0.900],
		['nicolas maduro moros', 'nicolás maduro', 0.898],
		['nicolas, maduro moros', 'maduro', 0.897],
		['nicolas, maduro moros', 'nicolas maduro', 0.928],
		['nicolas, maduro moros', 'nicolás', 0.822],
		['nicolas, maduro moros', 'maduro', 0.897],
		['nicolas, maduro moros', 'nicolás maduro', 0.906],
		['africada financial services bureau change', 'skylight', 0.441],
		['africada financial services bureau change', 'skylight financial inc', 0.670],
		['africada financial services bureau change', 'skylight services inc', 0.625],
		['africada financial services bureau change', 'skylight financial services', 0.778],
		['africada financial services bureau change', 'skylight financial services inc', 0.749],

		
		['the group for the preservation of the holy sites', 'the bridgespan group', 0.715],
		[N::lowerAndRemovePunctuation('the group for the preservation of the holy sites'), N::lowerAndRemovePunctuation('the bridgespan group'), 0.715],
		['group preservation holy sites', 'bridgespan group', 0.692],

		['the group for the preservation of the holy sites', 'the logan group', 0.670],
		[N::lowerAndRemovePunctuation('the group for the preservation of the holy sites'), N::lowerAndRemovePunctuation('the logan group'), 0.670],
		['group preservation holy sites', 'logan group', 0.586],

		['the group for the preservation of the holy sites', 'the anything group', 0.546],
		[N::lowerAndRemovePunctuation('the group for the preservation of the holy sites'), N::lowerAndRemovePunctuation('the anything group'), 0.546],
		['group preservation holy sites', 'anything group', 0.488],

		['the group for the preservation of the holy sites', 'the hello world group', 0.637],
		[N::lowerAndRemovePunctuation('the group for the preservation of the holy sites'), N::lowerAndRemovePunctuation('the hello world group'), 0.637],
		['group preservation holy sites', 'hello world group', 0.577],

		['the group for the preservation of the holy sites', 'the group', 0.880],
		[N::lowerAndRemovePunctuation('the group for the preservation of the holy sites'), N::lowerAndRemovePunctuation('the group'), 0.880],
		['group preservation holy sites', 'group', 0.879],

		['the group for the preservation of the holy sites', 'The flibbity jibbity flobbity jobbity grobbity zobbity group', 0.363],
		[
			N::lowerAndRemovePunctuation('the group for the preservation of the holy sites'),
			N::lowerAndRemovePunctuation('the flibbity jibbity flobbity jobbity grobbity zobbity group'),
			0.379,
		],
		['group preservation holy sites', 'flibbity jibbity flobbity jobbity grobbity zobbity group', 0.277],

		
		['i c sogo kenkyusho', N::lowerAndRemovePunctuation('A.I.C. SOGO KENKYUSHO'), 0.968],
		[N::lowerAndRemovePunctuation('A.I.C. SOGO KENKYUSHO'), 'sogo kenkyusho', 0.972],
		[N::lowerAndRemovePunctuation('11,420.2-1 CORP.'), '11 420 2 1 corp', 1.000],
		[N::lowerAndRemovePunctuation('11,420.2-1 CORP.'), '11 420 21 corp', 0.947],
		[N::lowerAndRemovePunctuation('11,420.2-1 CORP.'), '11420 2 1 corp', 0.849],
		[N::lowerAndRemovePunctuation('11,420.2-1 CORP.'), '11420 21 corp', 0.787],
		[N::lowerAndRemovePunctuation('11,420.2-1 CORP.'), '114202 1 corp', 0.802],
		[N::lowerAndRemovePunctuation('11,420.2-1 CORP.'), '1142021 corp', 0.752],

		
		['JSCARGUMENT', 'JSC ARGUMENT', 0.413],
		['ARGUMENTJSC', 'JSC ARGUMENT', 0.750],

		
		['ivan', 'john', 0.01], 
		['john smith', 'john smythe', 0.893],

		
		['sean', 'shawn', 0.757], 
		['mohamed', 'muhammed', 0.849],

		
		['a', 'a', 1.0],
		['a', 'b', 0.0],
		['', 'hello', 0.0],
		['hello', '', 0.0],
		['café', 'cafe', 0.8], 
		['123', '123', 1.0],
		['123', '456', 0.0],
		['hello world', 'hello   world', 1.0], 
		['very long string with many words', 'another very long string with different words', 0.725], 
		['!@#$%', '!@#$%', 1.0], 
		['!@#$%', 'abcde', 0.0],
		['αβγ', 'αβγ', 1.0], 
		['αβγ', 'abc', 0.0],
];
    }

    public function testBestPairsMatchesGo(): void
    {
        foreach (self::cases() as $i => [$indexed, $search, $expected]) {
            $got = JaroWinkler::bestPairs(Go::fields($search), Go::fields($indexed));
            $this->assertEqualsWithDelta($expected, round($got, 3), 0.0101, "#$i $indexed vs $search");
        }
    }

    public function testWordCombinations(): void
    {
        $this->assertSame([['jsc', 'argument'], ['jscargument']], JaroWinkler::wordCombinations(['jsc', 'argument']));
        $this->assertSame([['ab', 'cd', 'efgh'], ['abcd', 'efgh']], JaroWinkler::wordCombinations(['ab', 'cd', 'efgh']));
    }

    public function testSmetrics(): void
    {
        $this->assertEqualsWithDelta(0.961, JaroWinkler::smetrics('MARTHA', 'MARHTA', 0.7, 4), 0.001);
        $this->assertEqualsWithDelta(0.840, JaroWinkler::smetrics('DWAYNE', 'DUANE', 0.7, 4), 0.001);
        $this->assertEqualsWithDelta(0.813, JaroWinkler::smetrics('DIXON', 'DICKSONX', 0.7, 4), 0.001);
    }
}
