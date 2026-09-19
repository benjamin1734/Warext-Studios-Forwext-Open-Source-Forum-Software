<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Spellcheck;

final readonly class TurkishSpellcheckProvider implements SpellcheckProvider
{
    /** @var array<string,list<string>> */
    private const CORRECTIONS = [
        'herkez'=>['herkes'],
        'yanlız'=>['yalnız'],
        'yalnış'=>['yanlış'],
        'birşey'=>['bir şey'],
        'hiçbirşey'=>['hiçbir şey'],
        'şarz'=>['şarj'],
        'malesef'=>['maalesef'],
        'orjinal'=>['orijinal'],
        'traş'=>['tıraş'],
        'klavuz'=>['kılavuz'],
        'kirbit'=>['kibrit'],
        'aferim'=>['aferin'],
        'egsoz'=>['egzoz'],
        'süpriz'=>['sürpriz'],
        'labaratuvar'=>['laboratuvar'],
        'antreman'=>['antrenman'],
        'şöför'=>['şoför'],
        'poaça'=>['poğaça'],
        'maydonoz'=>['maydanoz'],
        'müsade'=>['müsaade'],
        'kiprik'=>['kirpik'],
        'eşortman'=>['eşofman'],
        'ünvan'=>['unvan'],
        'muhattap'=>['muhatap'],
        'iddiaa'=>['iddia'],
        'deyil'=>['değil'],
        'gelicek'=>['gelecek'],
        'yapıcak'=>['yapacak'],
        'gidicek'=>['gidecek'],
        'dicek'=>['diyecek'],
    ];

    public function key(): string
    {
        return 'turkish.core';
    }

    public function supports(string $language): bool
    {
        $language = SpellcheckLanguage::normalize($language);
        return $language === 'tr' || $language === 'tr-tr';
    }

    public function check(SpellcheckRequest $request, array $ignoredWords = []): SpellcheckResult
    {
        $ignored = [];
        foreach ($ignoredWords as $word) {
            if (is_string($word) && trim($word) !== '') {
                $ignored[SpellcheckWord::normalize($word)] = true;
            }
        }

        $matches = [];
        $count = preg_match_all('/\p{L}+(?:[’\'-]\p{L}+)*/u', $request->text, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false) {
            return new SpellcheckResult($this->key(), $request->language, []);
        }

        $issues = [];
        $previousByte = 0;
        $previousCharacters = 0;
        foreach ($matches[0] as $match) {
            if (!is_array($match) || !isset($match[0], $match[1])
                || !is_string($match[0]) || !is_int($match[1])
            ) {
                continue;
            }
            [$word, $byteOffset] = $match;
            $start = $previousCharacters + SpellcheckWord::characters(
                substr($request->text, $previousByte, $byteOffset - $previousByte),
            );
            $previousByte = $byteOffset;
            $previousCharacters = $start;

            $normalized = SpellcheckWord::normalize($word);
            if (isset($ignored[$normalized]) || !isset(self::CORRECTIONS[$normalized])) {
                continue;
            }

            $suggestions = array_map(
                fn (string $suggestion): string => $this->matchCase($word, $suggestion),
                self::CORRECTIONS[$normalized],
            );
            $issues[] = new SpellcheckIssue(
                $word,
                $start,
                SpellcheckWord::characters($word),
                $suggestions,
            );
        }

        return new SpellcheckResult($this->key(), $request->language, $issues);
    }

    private function matchCase(string $source, string $suggestion): string
    {
        if (preg_match('/^[A-ZÇĞİÖŞÜ]/u', $source) !== 1) {
            return $suggestion;
        }
        $first = preg_match('/^./us', $suggestion, $matches) === 1 ? $matches[0] : '';
        if ($first === '') {
            return $suggestion;
        }
        $upper = strtr(strtoupper($first), [
            'I'=>'I','İ'=>'İ','ı'=>'I','i'=>'İ','ş'=>'Ş','ğ'=>'Ğ','ü'=>'Ü','ö'=>'Ö','ç'=>'Ç',
        ]);
        return $upper . substr($suggestion, strlen($first));
    }
}
