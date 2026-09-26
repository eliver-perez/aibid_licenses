<?php
declare(strict_types=1);
namespace Aibid\Domain;

final class StrictJson
{
    public static function object(string $json, int $maxBytes = 16384): array
    {
        if (strlen($json) > $maxBytes) { throw new ApiProblem('INVALID_REQUEST', 413); }
        try { $value = json_decode($json, false, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiProblem('INVALID_REQUEST'); }
        if (!$value instanceof \stdClass) { throw new ApiProblem('INVALID_REQUEST'); }
        // json_decode validates grammar/UTF-8 first. Walk its lexical tokens to
        // reject duplicate decoded member names, including escaped equivalents.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,]+/s', $json, $matches);
        $index = 0;
        self::walk($matches[0], $index);
        return get_object_vars($value);
    }

    private static function walk(array $tokens, int &$index): void
    {
        $token = $tokens[$index++];
        if ($token === '{') {
            $seen = [];
            while ($tokens[$index] !== '}') {
                $key = 'key:' . json_decode($tokens[$index++], true, 16, JSON_THROW_ON_ERROR);
                if (isset($seen[$key])) { throw new ApiProblem('INVALID_REQUEST'); }
                $seen[$key] = true;
                ++$index; // colon
                self::walk($tokens, $index);
                if ($tokens[$index] !== ',') { break; }
                ++$index;
            }
            ++$index;
        } elseif ($token === '[') {
            while ($tokens[$index] !== ']') {
                self::walk($tokens, $index);
                if ($tokens[$index] !== ',') { break; }
                ++$index;
            }
            ++$index;
        }
    }
}
