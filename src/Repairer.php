<?php

declare(strict_types=1);

namespace Progineous\Mend;

/**
 * Deterministic repairs for markdown that a language model broke.
 * The engine is plain PHP. It does not parse markdown into HTML.
 */
final class Repairer
{
    private const ARABIC_INDIC = [
        "\u{0660}" => '0',
        "\u{0661}" => '1',
        "\u{0662}" => '2',
        "\u{0663}" => '3',
        "\u{0664}" => '4',
        "\u{0665}" => '5',
        "\u{0666}" => '6',
        "\u{0667}" => '7',
        "\u{0668}" => '8',
        "\u{0669}" => '9',
    ];

    public function repair(string $markdown): string
    {
        $markdown = $this->stripHidden($markdown);
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = $this->unwrapMarkdownDocument($markdown);
        $markdown = $this->repairOutsideFences($markdown);

        return $this->closeUnclosedFence($markdown);
    }

    /**
     * These characters can hide or reorder text, including inside code fences.
     * Arabic letters and Arabic punctuation are not in this set.
     */
    private function stripHidden(string $markdown): string
    {
        $stripped = preg_replace(
            '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u',
            '',
            $markdown
        );

        return $stripped ?? $markdown;
    }

    /**
     * Drop one outer fence when the whole document is a markdown or md block.
     * Inner fences stay. A wrapper that never closes is removed as well,
     * so the text inside can be repaired instead of being treated as code.
     */
    private function unwrapMarkdownDocument(string $markdown): string
    {
        $trimmed = trim($markdown, "\n");
        if ($trimmed === '') {
            return $markdown;
        }

        $lines = explode("\n", $trimmed);
        if (!$this->isMarkdownInfoOpener($lines[0], $marker, $length)) {
            return $markdown;
        }

        $stack = [[$marker, $length]];
        $last = count($lines) - 1;
        for ($i = 1; $i <= $last; $i++) {
            $top = $stack[array_key_last($stack)];
            if ($this->isClosingFence($lines[$i], $top[0], $top[1])) {
                array_pop($stack);
                if ($stack === [] && $i !== $last) {
                    return $markdown;
                }
                continue;
            }
            if ($this->parseOpener($lines[$i], $nestedMarker, $nestedLength)) {
                $stack[] = [$nestedMarker, $nestedLength];
            }
        }

        if ($stack === []) {
            return $this->joinLines(array_slice($lines, 1, -1));
        }

        if ($stack[0] === [$marker, $length]) {
            return $this->joinLines(array_slice($lines, 1));
        }

        return $markdown;
    }

    /**
     * @param array<int, string> $lines
     */
    private function joinLines(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function repairOutsideFences(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        $lines = explode("\n", $markdown);
        $segments = $this->splitFenceSegments($lines);
        $output = [];

        foreach ($segments as $segment) {
            if ($segment['fence']) {
                foreach ($segment['lines'] as $line) {
                    $output[] = $line;
                }
                continue;
            }

            $repaired = $this->repairText(implode("\n", $segment['lines']));
            foreach (explode("\n", $repaired) as $line) {
                $output[] = $line;
            }
        }

        return implode("\n", $output);
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array{fence: bool, lines: array<int, string>}>
     */
    private function splitFenceSegments(array $lines): array
    {
        $segments = [];
        $buffer = [];
        $openMarker = null;
        $openLength = 0;

        $flush = static function () use (&$buffer, &$segments): void {
            if ($buffer === []) {
                return;
            }
            $segments[] = ['fence' => false, 'lines' => $buffer];
            $buffer = [];
        };

        foreach ($lines as $line) {
            if ($openMarker === null) {
                if ($this->parseOpener($line, $marker, $length)) {
                    $flush();
                    $segments[] = ['fence' => true, 'lines' => [$line]];
                    $openMarker = $marker;
                    $openLength = $length;
                    continue;
                }
                $buffer[] = $line;
                continue;
            }

            $last = array_key_last($segments);
            $segments[$last]['lines'][] = $line;
            if ($this->isClosingFence($line, $openMarker, $openLength)) {
                $openMarker = null;
                $openLength = 0;
            }
        }

        $flush();

        return $segments;
    }

    private function repairText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = $this->stripActiveHtml($text);
        $text = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
            ['"', '"', "'", "'"],
            $text
        );

        $lines = explode("\n", $text);
        foreach ($lines as $index => $line) {
            $lines[$index] = $this->convertListMarker($line);
        }
        $lines = $this->repairTables($lines);
        foreach ($lines as $index => $line) {
            $lines[$index] = $this->closeObviousBacktick($line);
        }
        $lines = $this->closeUnclosedBold($lines);

        return implode("\n", $lines);
    }

    private function stripActiveHtml(string $text): string
    {
        $paired = '/<(script|iframe|object|embed)\b[^>]*>.*?<\/\1\s*>/is';
        $previous = null;
        while ($previous !== $text) {
            $previous = $text;
            $replaced = preg_replace($paired, '', $text);
            if (!is_string($replaced)) {
                break;
            }
            $text = $replaced;
        }

        $stripped = preg_replace('/<\/?(?:script|iframe|object|embed)\b[^>]*>/i', '', $text);

        return $this->stripEventHandlers(is_string($stripped) ? $stripped : $text);
    }

    private function stripEventHandlers(string $text): string
    {
        $stripped = preg_replace_callback(
            '/<[^>]+>/',
            static function (array $match): string {
                $tag = preg_replace(
                    '/\s+on[a-z0-9]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i',
                    '',
                    $match[0]
                );

                return is_string($tag) ? $tag : $match[0];
            },
            $text
        );

        return is_string($stripped) ? $stripped : $text;
    }

    private function convertListMarker(string $line): string
    {
        $bullet = preg_replace('/^([ \t]*)[\x{2022}\x{25CF}][ \t]*/u', '$1- ', $line, 1, $count);
        if (is_string($bullet) && $count > 0) {
            return $bullet;
        }

        if (!preg_match('/^([ \t]*)([\x{0660}-\x{0669}]+)\.([ \t]*)(.*)$/u', $line, $matches)) {
            return $line;
        }

        $rest = $matches[4];
        if ($matches[3] === '' && $rest !== '' && preg_match('/^[\x{0660}-\x{0669}0-9]/u', $rest)) {
            return $line;
        }

        $number = strtr($matches[2], self::ARABIC_INDIC);
        if ($rest === '') {
            return $matches[1] . $number . '.';
        }

        return $matches[1] . $number . '. ' . $rest;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function repairTables(array $lines): array
    {
        $count = count($lines);
        $output = [];
        $index = 0;

        while ($index < $count) {
            if (!$this->isTableStart($lines[$index])) {
                $output[] = $lines[$index];
                $index++;
                continue;
            }

            $block = [$lines[$index]];
            $index++;
            while ($index < $count && $this->isTableContinuation($lines[$index])) {
                $block[] = $lines[$index];
                $index++;
            }

            foreach ($this->repairTableBlock($block) as $row) {
                $output[] = $row;
            }
        }

        return $output;
    }

    /**
     * @param array<int, string> $block
     * @return array<int, string>
     */
    private function repairTableBlock(array $block): array
    {
        if (count($block) < 2 || $this->isSeparatorRow($block[0])) {
            return $block;
        }

        $columns = count($this->splitCells($block[0]));
        if ($columns < 1) {
            return $block;
        }

        $output = [$block[0]];
        $bodyStart = 1;
        if ($this->isSeparatorRow($block[1])) {
            $output[] = $block[1];
            $bodyStart = 2;
        } else {
            $output[] = $this->separatorFor($block[0], $columns);
        }

        for ($index = $bodyStart; $index < count($block); $index++) {
            if ($this->isSeparatorRow($block[$index])) {
                $output[] = $block[$index];
                continue;
            }
            $output[] = $this->padRow($block[$index], $columns);
        }

        return $output;
    }

    private function separatorFor(string $header, int $columns): string
    {
        preg_match('/^( *)/', $header, $indent);
        $cells = array_fill(0, $columns, '---');
        $body = implode(' | ', $cells);
        if (str_starts_with(trim($header), '|')) {
            return $indent[1] . '| ' . $body . ' |';
        }

        return $indent[1] . $body;
    }

    private function padRow(string $line, int $columns): string
    {
        $missing = $columns - count($this->splitCells($line));
        if ($missing <= 0) {
            return $line;
        }

        $core = rtrim($line);
        $extra = str_ends_with($core, '|') ? $missing : $missing + 1;

        return $core . str_repeat(' |', $extra);
    }

    /**
     * @return array<int, string>
     */
    private function splitCells(string $line): array
    {
        $trim = trim($line);
        $starts = str_starts_with($trim, '|');
        $ends = str_ends_with($trim, '|');
        $parts = explode('|', $trim);
        if ($starts) {
            array_shift($parts);
        }
        if ($ends) {
            array_pop($parts);
        }

        return $parts;
    }

    private function isTableStart(string $line): bool
    {
        $trim = trim($line);
        if ($trim === '' || !str_contains($trim, '|')) {
            return false;
        }
        if ($this->isSeparatorRow($line) || str_starts_with($trim, '|')) {
            return true;
        }

        return count($this->splitCells($line)) >= 2;
    }

    private function isTableContinuation(string $line): bool
    {
        return trim($line) !== '' && str_contains($line, '|');
    }

    private function isSeparatorRow(string $line): bool
    {
        if (!str_contains($line, '|')) {
            return false;
        }

        $cells = $this->splitCells($line);
        if ($cells === []) {
            return false;
        }

        foreach ($cells as $cell) {
            if (!preg_match('/^\s*:?-{3,}:?\s*$/', $cell)) {
                return false;
            }
        }

        return true;
    }

    private function closeObviousBacktick(string $line): string
    {
        if (substr_count($line, '`') !== 1) {
            return $line;
        }

        if (preg_match('/\\\\`/', $line)) {
            return $line;
        }

        if (!preg_match('/(?:^|\s)`\S/u', $line)) {
            return $line;
        }

        return $line . '`';
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function closeUnclosedBold(array $lines): array
    {
        $count = count($lines);
        $index = 0;

        while ($index < $count) {
            if (trim($lines[$index]) === '') {
                $index++;
                continue;
            }

            $start = $index;
            while ($index < $count && trim($lines[$index]) !== '') {
                $index++;
            }

            $end = $index - 1;
            $paragraph = implode("\n", array_slice($lines, $start, $end - $start + 1));
            if ($this->boldMarkerCount($paragraph) % 2 === 1) {
                $lines[$end] .= '**';
            }
        }

        return $lines;
    }

    private function boldMarkerCount(string $text): int
    {
        $text = $this->withoutPairedCodeSpans($text);
        $count = 0;
        $length = strlen($text);

        for ($index = 0; $index < $length - 1; $index++) {
            if ($text[$index] === '\\') {
                $index++;
                continue;
            }
            if ($text[$index] === '*' && $text[$index + 1] === '*') {
                $count++;
                $index++;
            }
        }

        return $count;
    }

    private function withoutPairedCodeSpans(string $text): string
    {
        $length = strlen($text);
        $output = '';
        $index = 0;

        while ($index < $length) {
            if ($text[$index] !== '`') {
                $output .= $text[$index];
                $index++;
                continue;
            }

            $run = 0;
            while ($index + $run < $length && $text[$index + $run] === '`') {
                $run++;
            }

            $delimiter = str_repeat('`', $run);
            $close = strpos($text, $delimiter, $index + $run);
            if ($close === false) {
                $output .= substr($text, $index);
                break;
            }

            $output .= $delimiter;
            $output .= str_repeat(' ', $close - ($index + $run));
            $output .= $delimiter;
            $index = $close + $run;
        }

        return $output;
    }

    private function closeUnclosedFence(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        $lines = explode("\n", $markdown);
        $endsWithNewline = str_ends_with($markdown, "\n");
        $last = $endsWithNewline ? count($lines) - 2 : count($lines) - 1;
        $openMarker = null;
        $openLength = 0;

        for ($index = 0; $index <= $last; $index++) {
            if ($openMarker === null) {
                if ($this->parseOpener($lines[$index], $marker, $length)) {
                    $openMarker = $marker;
                    $openLength = $length;
                }
                continue;
            }

            if ($this->isClosingFence($lines[$index], $openMarker, $openLength)) {
                $openMarker = null;
                $openLength = 0;
            }
        }

        if ($openMarker === null) {
            return $markdown;
        }

        $closer = str_repeat($openMarker, $openLength);
        if ($endsWithNewline) {
            return $markdown . $closer . "\n";
        }

        return $markdown . "\n" . $closer . "\n";
    }

    /**
     * @param-out string $marker
     * @param-out int $length
     */
    private function isMarkdownInfoOpener(string $line, ?string &$marker = null, ?int &$length = null): bool
    {
        if (!$this->parseOpener($line, $marker, $length)) {
            return false;
        }

        if (!preg_match('/^ {0,3}(?:`{3,}|~{3,})[ \t]*([^ \t]*)[ \t]*$/', $line, $matches)) {
            return false;
        }

        $info = strtolower($matches[1]);

        return $info === 'markdown' || $info === 'md';
    }

    /**
     * @param-out string $marker
     * @param-out int $length
     */
    private function parseOpener(string $line, ?string &$marker = null, ?int &$length = null): bool
    {
        if (!preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $matches)) {
            return false;
        }

        $info = ltrim($matches[2], " \t");
        if ($matches[1][0] === '`' && str_contains($info, '`')) {
            return false;
        }

        $marker = $matches[1][0];
        $length = strlen($matches[1]);

        return true;
    }

    private function isClosingFence(string $line, string $marker, int $length): bool
    {
        $pattern = '/^ {0,3}' . preg_quote($marker, '/') . '{' . $length . ',}[ \t]*$/';

        return preg_match($pattern, $line) === 1;
    }
}
