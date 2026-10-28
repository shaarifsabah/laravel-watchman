<?php

declare(strict_types=1);

namespace Watchman\Norm;

use Watchman\Support\Data;
use Watchman\Support\Go;

/** Port of internal/norm/domain.go (uses the Public Suffix List). */
final class Domain
{
    private const PUBLIC_EMAIL_HOSTS = [
        'gmail.com', 'googlemail.com', 'gmail.co.uk', 'yahoo.com', 'yahoo.co.uk', 'yahoo.co.jp', 'yahoo.fr',
        'yahoo.de', 'yahoo.it', 'yahoo.es', 'yahoo.com.br', 'yahoo.com.mx', 'yahoo.co.in', 'ymail.com', 'rocketmail.com',
        'hotmail.com', 'hotmail.co.uk', 'hotmail.fr', 'hotmail.it', 'outlook.com', 'outlook.co.uk', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com', 'aol.com', 'mail.ru', 'inbox.ru', 'list.ru', 'bk.ru', 'rambler.ru',
        'yandex.ru', 'yandex.com', 'qq.com', '163.com', '126.com', 'sina.com', 'sohu.com', 'proton.me', 'protonmail.com',
        'pm.me', 'gmx.com', 'gmx.de', 'gmx.net', 'web.de', 't-online.de', 'zoho.com', 'mail.com', 'inbox.com',
        'fastmail.com', 'hey.com', 'tutanota.com', 'tuta.com', 'naver.com', 'daum.net', 'rediffmail.com',
        'libero.it', 'virgilio.it', 'orange.fr', 'wanadoo.fr', 'free.fr', 'laposte.net', 'seznam.cz', 'wp.pl', 'o2.pl',
        'ukr.net', 'comcast.net', 'att.net', 'sbcglobal.net', 'verizon.net', 'bellsouth.net', 'charter.net',
        'btinternet.com', 'sky.com', 'virginmedia.com',
    ];

    /** @return list<string> */
    public static function keys(string $input): array
    {
        $host = Website::normalize($input);
        if ($host === '') {
            return [];
        }
        $i = strrpos($host, ':');
        if ($i !== false && !str_contains($host, ']')) {
            $host = substr($host, 0, $i);
        }
        $host = trim($host, '.');
        if ($host === '') {
            return [];
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }
        $etld1 = self::effectiveTldPlusOne($host);
        if ($etld1 === null || $etld1 === '' || !Go::hasSuffix($host, $etld1)) {
            return [];
        }
        $out = [];
        while (true) {
            $out[] = $host;
            if ($host === $etld1) {
                break;
            }
            $dot = strpos($host, '.');
            if ($dot === false) {
                break;
            }
            $host = substr($host, $dot + 1);
        }

        return $out;
    }

    /** @return list<string> */
    public static function keysFromEmail(string $email): array
    {
        $email = Go::lower(Go::trimSpace($email));
        $at = strrpos($email, '@');
        if ($at === false || $at === strlen($email) - 1) {
            return [];
        }
        $keys = self::keys(substr($email, $at + 1));
        if ($keys === []) {
            return [];
        }
        if (in_array($keys[count($keys) - 1], self::PUBLIC_EMAIL_HOSTS, true)) {
            return [];
        }

        return $keys;
    }

    /**
     * golang.org/x/net/publicsuffix.PublicSuffix semantics (default rule "*").
     */
    public static function publicSuffix(string $domain): string
    {
        $rules = Data::get('public_suffix');
        $labels = explode('.', $domain);
        $n = count($labels);
        $suffixLen = 1; // default rule "*"
        for ($i = 0; $i < $n; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            $len = $n - $i;
            if (($rules[$candidate] ?? null) === 2) { // exception rule wins
                $suffixLen = $len - 1;
                break;
            }
            if (($rules[$candidate] ?? null) === 0 && $len > $suffixLen) {
                $suffixLen = $len;
            }
            if ($i + 1 <= $n - 1) {
                $wild = '*.' . implode('.', array_slice($labels, $i + 1));
                if (($rules[$wild] ?? null) === 1 && $len > $suffixLen) {
                    $suffixLen = $len;
                }
            }
        }

        return implode('.', array_slice($labels, $n - $suffixLen));
    }

    public static function effectiveTldPlusOne(string $domain): ?string
    {
        if (str_starts_with($domain, '.') || str_ends_with($domain, '.') || str_contains($domain, '..')) {
            return null;
        }
        $suffix = self::publicSuffix($domain);
        if (strlen($domain) <= strlen($suffix)) {
            return null;
        }
        $i = strlen($domain) - strlen($suffix) - 1;
        if ($domain[$i] !== '.') {
            return null;
        }
        $j = strrpos(substr($domain, 0, $i), '.');

        return substr($domain, $j === false ? 0 : $j + 1);
    }
}
