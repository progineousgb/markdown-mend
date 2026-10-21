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
     * These characters can hide or reorder text, including inside code fences:
     * U+200B, the bidi embeddings and overrides U+202A-U+202E, the bidi
     * isolates U+2066-U+2069, and U+FEFF. Arabic letters and punctuation are
     * not in this set. The zero-width non-joiner and joiner (U+200C, U+200D)
     * and the direction marks (U+200E, U+200F) are kept, because emoji
     * sequences, Persian and Urdu shaping, and mixed-direction text need them.
     */
    private function stripHidden(string $markdown): string
    {
        $stripped = preg_replace(
            '/[\x{200B}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u',
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
            $top = end($stack);
            if ($top === false) {
                break;
            }
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
     * @return list<array{fence: bool, lines: list<string>}>
     */
    private function splitFenceSegments(array $lines): array
    {
        $segments = [];
        $current = [];
        $openMarker = null;
        $openLength = 0;

        foreach ($lines as $line) {
            if ($openMarker === null) {
                if ($this->parseOpener($line, $marker, $length)) {
                    if ($current !== []) {
                        $segments[] = ['fence' => false, 'lines' => $current];
                    }
                    $current = [$line];
                    $openMarker = $marker;
                    $openLength = $length;
                    continue;
                }
                $current[] = $line;
                continue;
            }

            $current[] = $line;
            if ($this->isClosingFence($line, $openMarker, $openLength)) {
                $segments[] = ['fence' => true, 'lines' => $current];
                $current = [];
                $openMarker = null;
                $openLength = 0;
            }
        }

        if ($current !== []) {
            $segments[] = ['fence' => $openMarker !== null, 'lines' => $current];
        }

        return $segments;
    }

    private function repairText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Safety repairs run on all text outside fences, indented code included,
        // because a renderer may not read an indented block as code.
        $text = $this->stripActiveHtml($text);
        $text = $this->neutralizeMarkdownUrls($text);

        $output = [];
        foreach ($this->splitIndentedCode(explode("\n", $text)) as $segment) {
            if ($segment['code']) {
                foreach ($segment['lines'] as $line) {
                    $output[] = $line;
                }
                continue;
            }
            foreach (explode("\n", $this->repairProse(implode("\n", $segment['lines']))) as $line) {
                $output[] = $line;
            }
        }

        return implode("\n", $output);
    }

    private function repairProse(string $text): string
    {
        $text = $this->mapOutsideCodeSpans($text, static fn (string $part): string => str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
            ['"', '"', "'", "'"],
            $part
        ));

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
        // Repeat until stable so split tags such as "<scr<script>ipt>" cannot
        // reassemble into an active tag after one pass.
        $previous = null;
        while ($previous !== $text) {
            $previous = $text;
            $text = $this->stripActiveHtmlOnce($text);
        }

        return $text;
    }

    private function stripActiveHtmlOnce(string $text): string
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

    /**
     * Split lines into indented code blocks and everything else.
     * An indented block starts after a blank line (or at the start), uses four
     * spaces or a tab, and is not inside a list, where indentation means
     * continuation instead of code. Blank lines after a block are text.
     *
     * @param array<int, string> $lines
     * @return list<array{code: bool, lines: list<string>}>
     */
    private function splitIndentedCode(array $lines): array
    {
        $lines = array_values($lines);
        $flags = [];
        $inList = false;
        $inCode = false;
        $previousBlank = true;

        foreach ($lines as $line) {
            $blank = trim($line) === '';
            $indented = preg_match('/^(?: {4}|\t)/', $line) === 1;

            if ($inCode && ($blank || $indented)) {
                $flags[] = true;
                $previousBlank = $blank;
                continue;
            }
            $inCode = false;

            if (!$blank && !$indented) {
                if (preg_match('/^ {0,3}(?:[-+*]|\d{1,9}[.)])(?:[ \t]|$)/', $line) === 1) {
                    $inList = true;
                } elseif ($previousBlank) {
                    $inList = false;
                }
            }

            $inCode = $indented && !$blank && $previousBlank && !$inList;
            $flags[] = $inCode;
            $previousBlank = $blank;
        }

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            if (!$flags[$index]) {
                continue;
            }
            if (trim($lines[$index]) !== '') {
                // Walk back to the start of this block, keeping its inner blank lines.
                while ($index > 0 && $flags[$index - 1]) {
                    $index--;
                }
                continue;
            }
            $flags[$index] = false;
        }

        $segments = [];
        $current = [];
        $currentCode = false;
        foreach ($lines as $index => $line) {
            if ($current !== [] && $flags[$index] !== $currentCode) {
                $segments[] = ['code' => $currentCode, 'lines' => $current];
                $current = [];
            }
            $currentCode = $flags[$index];
            $current[] = $line;
        }
        if ($current !== []) {
            $segments[] = ['code' => $currentCode, 'lines' => $current];
        }

        return $segments;
    }

    /**
     * Apply a callback to the text outside paired inline code spans.
     *
     * @param callable(string): string $callback
     */
    private function mapOutsideCodeSpans(string $text, callable $callback): string
    {
        $length = strlen($text);
        $output = '';
        $plain = '';
        $index = 0;

        while ($index < $length) {
            if ($text[$index] !== '`') {
                $plain .= $text[$index];
                $index++;
                continue;
            }

            $run = 0;
            while ($index + $run < $length && $text[$index + $run] === '`') {
                $run++;
            }

            $close = $this->findClosingRun($text, $run, $index + $run);
            if ($close === null) {
                $plain .= substr($text, $index, $run);
                $index += $run;
                continue;
            }

            $output .= $callback($plain) . substr($text, $index, $close + $run - $index);
            $plain = '';
            $index = $close + $run;
        }

        return $output . $callback($plain);
    }

    private function findClosingRun(string $text, int $run, int $offset): ?int
    {
        $length = strlen($text);
        while (($position = strpos($text, str_repeat('`', $run), $offset)) !== false) {
            $end = $position;
            while ($end < $length && $text[$end] === '`') {
                $end++;
            }
            if ($end - $position === $run) {
                return $position;
            }
            $offset = $end;
        }

        return null;
    }

    /**
     * True for javascript:, vbscript:, and data:text/html URLs, after decoding
     * HTML entities and dropping the whitespace and control characters that
     * browsers ignore inside a scheme.
     */
    private function isDangerousUrl(string $url): bool
    {
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $compact = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $decoded));
        $compact = ltrim($compact, '<');

        return preg_match('/^(?:javascript:|vbscript:|data:text\/html)/', $compact) === 1;
    }

    /**
     * Replace dangerous URLs in inline links and images, link reference
     * definitions, and autolinks with "#".
     */
    private function neutralizeMarkdownUrls(string $text): string
    {
        $inline = preg_replace_callback(
            '/(\]\(\s*)(<[^>\n]*>|(?:[^\s()]|\([^\s()]*\))+)/',
            fn (array $m): string => $m[1] . ($this->isDangerousUrl($m[2]) ? '#' : $m[2]),
            $text
        );
        $text = is_string($inline) ? $inline : $text;

        $reference = preg_replace_callback(
            '/^( {0,3}\[[^\]\n]+\]:[ \t]*)(<[^>\n]*>|\S+)/m',
            fn (array $m): string => $m[1] . ($this->isDangerousUrl($m[2]) ? '#' : $m[2]),
            $text
        );
        $text = is_string($reference) ? $reference : $text;

        $autolink = preg_replace_callback(
            '/<([a-z][a-z0-9+.\-]{1,31}:[^\s<>]*)>/i',
            fn (array $m): string => $this->isDangerousUrl($m[1]) ? '#' : $m[0],
            $text
        );

        return is_string($autolink) ? $autolink : $text;
    }

    private function stripEventHandlers(string $text): string
    {
        $stripped = preg_replace_callback(
            '/<[a-z][a-z0-9:-]*(?:"[^"]*"|\'[^\']*\'|[^"\'>])*>/i',
            function (array $match): string {
                $tag = preg_replace(
                    '/[\s\/]+on[a-z0-9_-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i',
                    '',
                    $match[0]
                );
                $tag = is_string($tag) ? $tag : $match[0];

                $urls = preg_replace_callback(
                    '/([\s\/]+(?:href|src|xlink:href|action|formaction|data|poster|background)\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i',
                    fn (array $m): string => $this->isDangerousUrl(trim($m[2], '"\'')) ? $m[1] . '"#"' : $m[0],
                    $tag
                );

                return is_string($urls) ? $urls : $tag;
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
        $prefix = preg_match('/^((?:[ \t]*>)*[ \t]*)/', $header, $indent) === 1 ? $indent[1] : '';
        $cells = array_fill(0, $columns, '---');
        $body = implode(' | ', $cells);
        if (str_starts_with(ltrim(substr($header, strlen($prefix))), '|')) {
            return $prefix . '| ' . $body . ' |';
        }

        return $prefix . $body;
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
        $trim = trim((string) preg_replace('/^(?:[ \t]*>)+/', '', $line));
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
            if ($this->boldMarkerCount($paragraph) % 2 === 1 && !str_contains($lines[$end], '|')) {
                $lines[$end] .= '**';
            }
        }

        return $lines;
    }

    private function boldMarkerCount(string $text): int
    {
        // A thematic break such as "***" or "* * *" is not emphasis.
        $text = (string) preg_replace('/^ {0,3}(?:\*[ \t]*){3,}$/m', '', $text);
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
