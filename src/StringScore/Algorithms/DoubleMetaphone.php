<?php

declare(strict_types=1);

namespace Watchman\StringScore\Algorithms;

/**
 * Double Metaphone (Philips, C/C++ Users Journal, June 2000), codes capped at
 * 4 characters.
 *
 * Port of github.com/PhonoGrams/double_metaphone v0.1.0 (Encode, Match),
 * Apache License 2.0.
 *
 * Input is upper-cased with Go's strings.ToUpper and reduced to the runes for
 * which Go's unicode.IsLetter is true (so spaces, digits and punctuation are
 * dropped before encoding, exactly as upstream does).
 */
final class DoubleMetaphone
{
    private const MAX_LEN = 4;

    /** @var list<int> upper-cased letter runes */
    private array $s = [];
    private int $n = 0;
    private int $i = 0;
    private int $last = 0;
    private string $pri = '';
    private string $alt = '';
    private bool $slavo = false;

    private function __construct()
    {
    }

    /** True when a and b share a primary or alternate code. */
    public static function match(string $s1, string $s2): bool
    {
        [$p1, $a1] = self::encode($s1);
        [$p2, $a2] = self::encode($s2);
        foreach ([$p1, $a1] as $x) {
            if ($x === '') {
                continue;
            }
            if ($x === $p2 || $x === $a2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Primary and alternate codes (alternate is '' when equal to primary).
     *
     * @return array{0: string, 1: string}
     */
    public static function encode(string $s): array
    {
        $e = new self();
        foreach (GoUnicode::runes($s) as $r) {
            $r = GoUnicode::toUpper($r);
            if (GoUnicode::isLetter($r)) {
                $e->s[] = $r;
            }
        }
        $e->n = \count($e->s);
        if ($e->n === 0) {
            return ['', ''];
        }
        $e->last = $e->n - 1;
        $e->slavo = $e->slavoGermanic();
        $e->i = 0;
        if ($e->stringAt(0, 'GN', 'KN', 'PN', 'WR', 'PS')) {
            $e->i = 1;
        }
        if ($e->at(0) === 0x58) { // 'X'
            $e->add('S', 'S');
            $e->i = 1;
        }
        while ($e->i < $e->n && \strlen($e->pri) < self::MAX_LEN && \strlen($e->alt) < self::MAX_LEN) {
            $e->step();
        }
        $p = substr($e->pri, 0, self::MAX_LEN);
        $a = substr($e->alt, 0, self::MAX_LEN);
        if ($a === $p) {
            $a = '';
        }

        return [$p, $a];
    }

    private function slavoGermanic(): bool
    {
        return $this->contains('W') || $this->contains('K') || $this->contains('CZ') || $this->contains('WITZ');
    }

    private function contains(string $tok): bool
    {
        $len = \strlen($tok);
        for ($k = 0; $k + $len <= $this->n; $k++) {
            if ($this->stringAt($k, $tok)) {
                return true;
            }
        }

        return false;
    }

    private function at(int $i): int
    {
        if ($i < 0 || $i >= $this->n) {
            return 0;
        }

        return $this->s[$i];
    }

    /** Is the rune at $i one of 'A','E','I','O','U','Y'. */
    private function isVowel(int $i): bool
    {
        $c = $this->at($i);

        return $c === 0x41 || $c === 0x45 || $c === 0x49 || $c === 0x4F || $c === 0x55 || $c === 0x59;
    }

    /** Does the rune at $i equal the single ASCII character $c. */
    private function is(int $i, string $c): bool
    {
        return $this->at($i) === \ord($c);
    }

    /** Tokens are ASCII, so their byte offsets are rune offsets. */
    private function stringAt(int $start, string ...$tokens): bool
    {
        if ($start < 0) {
            return false;
        }
        foreach ($tokens as $tok) {
            $len = \strlen($tok);
            if ($start + $len > $this->n) {
                continue;
            }
            $ok = true;
            for ($k = 0; $k < $len; $k++) {
                if ($this->s[$start + $k] !== \ord($tok[$k])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function add(string $p, string $a): void
    {
        $this->pri .= $p;
        if ($a === '') {
            $a = $p;
        }
        $this->alt .= $a;
    }

    /** Advance by 2 if the next rune is $c, else by 1. */
    private function skipDouble(string $c): void
    {
        $this->i += $this->is($this->i + 1, $c) ? 2 : 1;
    }

    private function step(): void
    {
        $c = $this->at($this->i);
        switch ($c) {
            case 0x41: case 0x45: case 0x49: case 0x4F: case 0x55: case 0x59: // A E I O U Y
                if ($this->i === 0) {
                    $this->add('A', 'A');
                }
                $this->i++;
                return;
            case 0x42: // B
                $this->add('P', 'P');
                $this->skipDouble('B');
                return;
            case 0xC7: // Ç
                $this->add('S', 'S');
                $this->i++;
                return;
            case 0x43: // C
                $this->stepC();
                return;
            case 0x44: // D
                $this->stepD();
                return;
            case 0x46: // F
                $this->add('F', 'F');
                $this->skipDouble('F');
                return;
            case 0x47: // G
                $this->stepG();
                return;
            case 0x48: // H
                if (($this->i === 0 || $this->isVowel($this->i - 1)) && $this->isVowel($this->i + 1)) {
                    $this->add('H', 'H');
                    $this->i += 2;
                } else {
                    $this->i++;
                }
                return;
            case 0x4A: // J
                $this->stepJ();
                return;
            case 0x4B: // K
                $this->add('K', 'K');
                $this->skipDouble('K');
                return;
            case 0x4C: // L
                if ($this->is($this->i + 1, 'L')) {
                    if (($this->i === $this->n - 3 && $this->stringAt($this->i - 1, 'ILLO', 'ILLA', 'ALLE'))
                        || (($this->stringAt($this->last - 1, 'AS', 'OS') || $this->is($this->last, 'A') || $this->is($this->last, 'O'))
                            && $this->stringAt($this->i - 1, 'ALLE'))) {
                        $this->add('L', '');
                        $this->i += 2;
                        return;
                    }
                    $this->i += 2;
                } else {
                    $this->i++;
                }
                $this->add('L', 'L');
                return;
            case 0x4D: // M
                if (($this->stringAt($this->i - 1, 'UMB') && ($this->i + 1 === $this->last || $this->stringAt($this->i + 2, 'ER')))
                    || $this->is($this->i + 1, 'M')) {
                    $this->i += 2;
                } else {
                    $this->i++;
                }
                $this->add('M', 'M');
                return;
            case 0x4E: // N
                $this->add('N', 'N');
                $this->skipDouble('N');
                return;
            case 0xD1: // Ñ
                $this->add('N', 'N');
                $this->i++;
                return;
            case 0x50: // P
                if ($this->is($this->i + 1, 'H')) {
                    $this->add('F', 'F');
                    $this->i += 2;
                    return;
                }
                $this->add('P', 'P');
                if ($this->is($this->i + 1, 'P') || $this->is($this->i + 1, 'B')) {
                    $this->i += 2;
                } else {
                    $this->i++;
                }
                return;
            case 0x51: // Q
                $this->add('K', 'K');
                $this->skipDouble('Q');
                return;
            case 0x52: // R
                if ($this->i === $this->last && !$this->slavo && $this->stringAt($this->i - 2, 'IE')
                    && !$this->stringAt($this->i - 4, 'ME', 'MA')) {
                    $this->add('', 'R');
                } else {
                    $this->add('R', 'R');
                }
                $this->skipDouble('R');
                return;
            case 0x53: // S
                $this->stepS();
                return;
            case 0x54: // T
                $this->stepT();
                return;
            case 0x56: // V
                $this->add('F', 'F');
                $this->skipDouble('V');
                return;
            case 0x57: // W
                $this->stepW();
                return;
            case 0x58: // X
                $this->stepX();
                return;
            case 0x5A: // Z
                $this->stepZ();
                return;
            default:
                $this->i++;
        }
    }

    private function stepC(): void
    {
        $i = $this->i;
        if ($i > 1 && !$this->isVowel($i - 2) && $this->stringAt($i - 1, 'ACH')
            && (!$this->is($i + 2, 'I') && (!$this->is($i + 2, 'E') || $this->stringAt($i - 2, 'BACHER', 'MACHER')))) {
            $this->add('K', 'K');
            $this->i += 2;
            return;
        }
        if ($i === 0 && $this->stringAt($i, 'CAESAR')) {
            $this->add('S', 'S');
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i, 'CHIA')) {
            $this->add('K', 'K');
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i, 'CH')) {
            if ($i > 0 && $this->stringAt($i, 'CHAE')) {
                $this->add('K', 'X');
                $this->i += 2;
                return;
            }
            if ($i === 0 && ($this->stringAt($i + 1, 'HARAC', 'HARIS') || $this->stringAt($i + 1, 'HOR', 'HYM', 'HIA', 'HEM'))
                && !$this->stringAt(0, 'CHORE')) {
                $this->add('K', 'K');
                $this->i += 2;
                return;
            }
            $c2 = $this->at($i + 2);
            if (($this->stringAt(0, 'VAN ', 'VON ') || $this->stringAt(0, 'SCH'))
                || $this->stringAt($i - 2, 'ORCHES', 'ARCHIT', 'ORCHID')
                || $c2 === 0x54 || $c2 === 0x53 // T S
                || (($this->is($i - 1, 'A') || $this->is($i - 1, 'O') || $this->is($i - 1, 'U') || $this->is($i - 1, 'E')) || $i === 0)
                    && ($c2 === 0x4C || $c2 === 0x52 || $c2 === 0x4E || $c2 === 0x4D || $c2 === 0x42 // L R N M B
                        || $c2 === 0x48 || $c2 === 0x46 || $c2 === 0x56 || $c2 === 0x57 || $c2 === 0)) { // H F V W NUL
                $this->add('K', 'K');
            } elseif ($i > 0) {
                if ($this->stringAt(0, 'MC')) {
                    $this->add('K', 'K');
                } else {
                    $this->add('X', 'K');
                }
            } else {
                $this->add('X', 'X');
            }
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i, 'CZ') && !$this->stringAt($i - 2, 'WICZ')) {
            $this->add('S', 'X');
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i + 1, 'CIA')) {
            $this->add('X', 'X');
            $this->i += 3;
            return;
        }
        if ($this->stringAt($i, 'CC') && !($i === 1 && $this->is(0, 'M'))) {
            if ($this->stringAt($i + 2, 'I', 'E', 'H') && !$this->stringAt($i + 2, 'HU')) {
                if (($i === 1 && $this->is($i - 1, 'A')) || $this->stringAt($i - 1, 'UCCEE', 'UCCES')) {
                    $this->add('KS', 'KS');
                } else {
                    $this->add('X', 'X');
                }
                $this->i += 3;
                return;
            }
            $this->add('K', 'K');
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i, 'CK', 'CG', 'CQ')) {
            $this->add('K', 'K');
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i, 'CI', 'CE', 'CY')) {
            if ($this->stringAt($i, 'CIO', 'CIE', 'CIA')) {
                $this->add('S', 'X');
            } else {
                $this->add('S', 'S');
            }
            $this->i += 2;
            return;
        }
        $this->add('K', 'K');
        if ($this->stringAt($i + 1, ' C', ' Q', ' G')) {
            $this->i += 3;
        } elseif ($this->stringAt($i + 1, 'C', 'K', 'Q') && !$this->stringAt($i + 1, 'CE', 'CI')) {
            $this->i += 2;
        } else {
            $this->i++;
        }
    }

    private function stepD(): void
    {
        $i = $this->i;
        if ($this->stringAt($i, 'DG')) {
            if ($this->stringAt($i + 2, 'I', 'E', 'Y')) {
                $this->add('J', 'J');
                $this->i += 3;
                return;
            }
            $this->add('TK', 'TK');
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i, 'DT', 'DD')) {
            $this->add('T', 'T');
            $this->i += 2;
            return;
        }
        $this->add('T', 'T');
        $this->i++;
    }

    private function stepG(): void
    {
        $i = $this->i;
        if ($this->is($i + 1, 'H')) {
            if ($i > 0 && !$this->isVowel($i - 1)) {
                $this->add('K', 'K');
                $this->i += 2;
                return;
            }
            if ($i === 0) {
                if ($this->is($i + 2, 'I')) {
                    $this->add('J', 'J');
                } else {
                    $this->add('K', 'K');
                }
                $this->i += 2;
                return;
            }
            if (($i > 1 && ($this->is($i - 2, 'B') || $this->is($i - 2, 'H') || $this->is($i - 2, 'D')))
                || ($i > 2 && ($this->is($i - 3, 'B') || $this->is($i - 3, 'H') || $this->is($i - 3, 'D')))
                || ($i > 3 && ($this->is($i - 4, 'B') || $this->is($i - 4, 'H')))) {
                $this->i += 2;
                return;
            }
            if ($i > 2 && $this->is($i - 1, 'U')
                && ($this->is($i - 3, 'C') || $this->is($i - 3, 'G') || $this->is($i - 3, 'L') || $this->is($i - 3, 'R') || $this->is($i - 3, 'T'))) {
                $this->add('F', 'F');
            } elseif ($i > 0 && !$this->is($i - 1, 'I')) {
                $this->add('K', 'K');
            }
            $this->i += 2;
            return;
        }
        if ($this->is($i + 1, 'N')) {
            if ($i === 1 && $this->isVowel(0) && !$this->slavo) {
                $this->add('KN', 'N');
            } elseif (!$this->stringAt($i + 2, 'EY') && !$this->is($i + 1, 'Y') && !$this->slavo) {
                $this->add('N', 'KN');
            } else {
                $this->add('KN', 'KN');
            }
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i + 1, 'LI') && !$this->slavo) {
            $this->add('KL', 'L');
            $this->i += 2;
            return;
        }
        if ($i === 0 && ($this->is($i + 1, 'Y')
                || $this->stringAt($i + 1, 'ES', 'EP', 'EB', 'EL', 'EY', 'IB', 'IL', 'IN', 'IE', 'EI', 'ER'))) {
            $this->add('K', 'J');
            $this->i += 2;
            return;
        }
        if (($this->stringAt($i + 1, 'ER') || $this->is($i + 1, 'Y'))
            && !$this->stringAt(0, 'DANGER', 'RANGER', 'MANGER')
            && !$this->stringAt($i - 1, 'E', 'I') && !$this->stringAt($i - 1, 'RGY', 'OGY')) {
            $this->add('K', 'J');
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i + 1, 'E', 'I', 'Y') || $this->stringAt($i - 1, 'AGGI', 'OGGI')) {
            if ($this->stringAt(0, 'VAN ', 'VON ') || $this->stringAt(0, 'SCH') || $this->stringAt($i + 1, 'ET')) {
                $this->add('K', 'K');
            } elseif ($this->stringAt($i + 1, 'IER')) {
                $this->add('J', 'J');
            } else {
                $this->add('J', 'K');
            }
            $this->i += 2;
            return;
        }
        $this->skipDouble('G');
        $this->add('K', 'K');
    }

    private function stepJ(): void
    {
        $i = $this->i;
        if ($this->stringAt($i, 'JOSE') || $this->stringAt(0, 'SAN ')) {
            if (($i === 0 && $this->is($i + 4, ' ')) || $this->stringAt(0, 'SAN ')) {
                $this->add('H', 'H');
            } else {
                $this->add('J', 'H');
            }
            $this->i++;
            return;
        }
        if ($i === 0 && !$this->stringAt($i, 'JOSE')) {
            $this->add('J', 'A');
        } elseif ($this->isVowel($i - 1) && !$this->slavo && ($this->is($i + 1, 'A') || $this->is($i + 1, 'O'))) {
            $this->add('J', 'H');
        } elseif ($i === $this->last) {
            $this->add('J', '');
        } elseif (!$this->stringAt($i + 1, 'L', 'T', 'K', 'S', 'N', 'M', 'B', 'Z') && !$this->stringAt($i - 1, 'S', 'K', 'L')) {
            $this->add('J', 'J');
        }
        $this->skipDouble('J');
    }

    private function stepS(): void
    {
        $i = $this->i;
        if ($this->stringAt($i - 1, 'ISL', 'YSL')) {
            $this->i++;
            return;
        }
        if ($i === 0 && $this->stringAt($i, 'SUGAR')) {
            $this->add('X', 'S');
            $this->i++;
            return;
        }
        if ($this->stringAt($i, 'SH')) {
            if ($this->stringAt($i + 1, 'HEIM', 'HOEK', 'HOLM', 'HOLZ')) {
                $this->add('S', 'S');
            } else {
                $this->add('X', 'X');
            }
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i, 'SIO', 'SIA') || $this->stringAt($i, 'SIAN')) {
            if (!$this->slavo) {
                $this->add('S', 'X');
            } else {
                $this->add('S', 'S');
            }
            $this->i += 3;
            return;
        }
        if (($i === 0 && $this->stringAt($i + 1, 'M', 'N', 'L', 'W')) || $this->stringAt($i + 1, 'Z')) {
            $this->add('S', 'X');
            if ($this->stringAt($i + 1, 'Z')) {
                $this->i += 2;
            } else {
                $this->i++;
            }
            return;
        }
        if ($this->stringAt($i, 'SC')) {
            if ($this->is($i + 2, 'H')) {
                if ($this->stringAt($i + 3, 'OO', 'ER', 'EN', 'UY', 'ED', 'EM')) {
                    if ($this->stringAt($i + 3, 'ER', 'EN')) {
                        $this->add('X', 'SK');
                    } else {
                        $this->add('SK', 'SK');
                    }
                } elseif ($i === 0 && !$this->isVowel(3) && !$this->is(3, 'W')) {
                    $this->add('X', 'S');
                } else {
                    $this->add('X', 'X');
                }
                $this->i += 3;
                return;
            }
            if ($this->stringAt($i + 2, 'I', 'E', 'Y')) {
                $this->add('S', 'S');
                $this->i += 3;
                return;
            }
            $this->add('SK', 'SK');
            $this->i += 3;
            return;
        }
        if ($i === $this->last && $this->stringAt($i - 2, 'AI', 'OI')) {
            $this->add('', 'S');
        } else {
            $this->add('S', 'S');
        }
        if ($this->stringAt($i + 1, 'S', 'Z')) {
            $this->i += 2;
        } else {
            $this->i++;
        }
    }

    private function stepT(): void
    {
        $i = $this->i;
        if ($this->stringAt($i, 'TION')) {
            $this->add('X', 'X');
            $this->i += 3;
            return;
        }
        if ($this->stringAt($i, 'TIA', 'TCH')) {
            $this->add('X', 'X');
            $this->i += 3;
            return;
        }
        if ($this->stringAt($i, 'TH') || $this->stringAt($i, 'TTH')) {
            if ($this->stringAt($i + 2, 'OM', 'AM') || $this->stringAt(0, 'VAN ', 'VON ') || $this->stringAt(0, 'SCH')) {
                $this->add('T', 'T');
            } else {
                $this->add('0', 'T');
            }
            $this->i += 2;
            return;
        }
        $this->add('T', 'T');
        if ($this->stringAt($i + 1, 'T', 'D')) {
            $this->i += 2;
        } else {
            $this->i++;
        }
    }

    private function stepW(): void
    {
        $i = $this->i;
        if ($this->stringAt($i, 'WR')) {
            $this->add('R', 'R');
            $this->i += 2;
            return;
        }
        if ($i === 0 && ($this->isVowel($i + 1) || $this->stringAt($i, 'WH'))) {
            if ($this->isVowel($i + 1)) {
                $this->add('A', 'F');
            } else {
                $this->add('A', 'A');
            }
        }
        if (($i === $this->last && $this->isVowel($i - 1))
            || $this->stringAt($i - 1, 'EWSKI', 'EWSKY', 'OWSKI', 'OWSKY') || $this->stringAt(0, 'SCH')) {
            $this->add('', 'F');
            $this->i++;
            return;
        }
        if ($this->stringAt($i, 'WICZ', 'WITZ')) {
            $this->add('TS', 'FX');
            $this->i += 4;
            return;
        }
        $this->i++;
    }

    private function stepX(): void
    {
        $i = $this->i;
        if ($i === 0) {
            $this->add('S', 'S');
            $this->i++;
            return;
        }
        if (!($i === $this->last && ($this->stringAt($i - 3, 'IAU', 'EAU') || $this->stringAt($i - 2, 'AU', 'OU')))) {
            $this->add('KS', 'KS');
        }
        if ($this->stringAt($i + 1, 'C', 'X')) {
            $this->i += 2;
        } else {
            $this->i++;
        }
    }

    private function stepZ(): void
    {
        $i = $this->i;
        if ($this->is($i + 1, 'H')) {
            $this->add('J', 'J');
            $this->i += 2;
            return;
        }
        if ($this->stringAt($i + 1, 'ZO', 'ZI', 'ZA') || ($this->slavo && $i > 0 && !$this->is($i - 1, 'T'))) {
            $this->add('S', 'TS');
        } else {
            $this->add('S', 'S');
        }
        $this->skipDouble('Z');
    }
}
