<?php

declare(strict_types=1);

namespace Progineous\Mend\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Progineous\Mend\Mend;
use Progineous\Mend\Repairer;

final class RepairTest extends TestCase
{
    #[DataProvider('repairs')]
    public function test_repair_maps_raw_input_to_exact_output(string $input, string $expected, bool $changes): void
    {
        if ($changes) {
            $this->assertNotSame($input, $expected);
        } else {
            $this->assertSame($input, $expected);
        }

        $this->assertSame($expected, Mend::repair($input));
    }

    public function test_instance_api_matches_the_static_api(): void
    {
        $input = "```\ncode";
        $this->assertSame(Mend::repair($input), (new Repairer())->repair($input));
    }

    public function test_laravel_wiring_is_present_without_booting_the_framework(): void
    {
        $root = dirname(__DIR__);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('progineousgb/markdown-mend', $composer['name']);
        $this->assertSame('>=8.2', $composer['require']['php']);
        $this->assertArrayNotHasKey('illuminate/support', $composer['require']);
        $this->assertStringContainsString('^12.0 || ^13.0', $composer['suggest']['illuminate/support']);
        $this->assertSame(
            'Progineous\\Mend\\MendServiceProvider',
            $composer['extra']['laravel']['providers'][0]
        );
        $this->assertSame(
            'Progineous\\Mend\\Facades\\Mend',
            $composer['extra']['laravel']['aliases']['Mend']
        );

        $provider = (string) file_get_contents($root . '/src/MendServiceProvider.php');
        $this->assertStringContainsString('extends ServiceProvider', $provider);
        $this->assertStringContainsString('Repairer::class', $provider);
        $this->assertStringNotContainsString('Mend::repair', $provider);

        $facade = (string) file_get_contents($root . '/src/Facades/Mend.php');
        $this->assertStringContainsString('extends Facade', $facade);
        $this->assertStringContainsString('return Repairer::class;', $facade);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function repairs(): array
    {
        $hidden = "\u{200B}\u{200C}\u{200D}\u{200E}\u{200F}\u{202A}\u{202B}\u{202C}\u{202D}\u{202E}\u{2066}\u{2067}\u{2068}\u{2069}\u{FEFF}";

        return [
            'unwraps a closed markdown fence' => [
                "```markdown\n# Title\n\nHello\n```\n",
                "# Title\n\nHello\n",
                true,
            ],
            'unwraps an md fence and repairs the table inside' => [
                "```md\n| A | B |\n| 1 |\n```\n",
                "| A | B |\n| --- | --- |\n| 1 | |\n",
                true,
            ],
            'unwraps markdown regardless of info-string case' => [
                "```Markdown\nHello\n```\n",
                "Hello\n",
                true,
            ],
            'unwraps a tilde fence whose info string is md' => [
                "~~~md\nHello\n~~~\n",
                "Hello\n",
                true,
            ],
            'unwraps a markdown fence that contains an inner code fence' => [
                "```markdown\n| A | B |\n| 1 |\n\n```php\necho 1;\n```\n```\n",
                "| A | B |\n| --- | --- |\n| 1 | |\n\n```php\necho 1;\n```\n",
                true,
            ],
            'unwraps an unclosed markdown fence so the inside can be repaired' => [
                "```md\n# Title\n\n**open\n",
                "# Title\n\n**open**\n",
                true,
            ],
            'leaves a php fence wrapped' => [
                "```php\necho 1;\n```\n",
                "```php\necho 1;\n```\n",
                false,
            ],
            'leaves a markdown fence that does not wrap the whole document' => [
                "intro\n```markdown\n# T\n```\n",
                "intro\n```markdown\n# T\n```\n",
                false,
            ],
            'closes an unclosed backtick fence' => [
                "```\necho 1;\n",
                "```\necho 1;\n```\n",
                true,
            ],
            'closes an unclosed fence that already ends with a newline' => [
                "```\ncode\n",
                "```\ncode\n```\n",
                true,
            ],
            'closes an unclosed tilde fence with tildes' => [
                "~~~\ncode",
                "~~~\ncode\n~~~\n",
                true,
            ],
            'closes a longer unclosed fence with the same length' => [
                "````\ncode\n```\n",
                "````\ncode\n```\n````\n",
                true,
            ],
            'does not close a fence that is already closed' => [
                "```\ncode\n```\n",
                "```\ncode\n```\n",
                false,
            ],
            'does not rewrite fence contents while closing the fence' => [
                "```\n• “bold **x\n<script>alert(1)</script>\n",
                "```\n• “bold **x\n<script>alert(1)</script>\n```\n",
                true,
            ],
            'inserts a missing table separator and pads a short row' => [
                "| Name | Age |\n| Ada | 36 |\n| Grace |\n",
                "| Name | Age |\n| --- | --- |\n| Ada | 36 |\n| Grace | |\n",
                true,
            ],
            'pads a short row when the separator is already present' => [
                "| Name | Age |\n| --- | --- |\n| Grace |\n",
                "| Name | Age |\n| --- | --- |\n| Grace | |\n",
                true,
            ],
            'pads a row to three columns' => [
                "| A | B | C |\n| only |\n",
                "| A | B | C |\n| --- | --- | --- |\n| only | | |\n",
                true,
            ],
            'inserts a separator for a table without leading pipes' => [
                "Name | Age\nAda |\n",
                "Name | Age\n--- | ---\nAda | |\n",
                true,
            ],
            'does not insert a second separator or drop alignment' => [
                "| Item | Price |\n| :--- | ---: |\n| Tea | 2 |\n",
                "| Item | Price |\n| :--- | ---: |\n| Tea | 2 |\n",
                false,
            ],
            'does not truncate a longer body row' => [
                "| A | B |\n| --- | --- |\n| 1 | 2 | 3 |\n",
                "| A | B |\n| --- | --- |\n| 1 | 2 | 3 |\n",
                false,
            ],
            'does not turn one pipe in a sentence into a table' => [
                "See a | b in this sentence.\n",
                "See a | b in this sentence.\n",
                false,
            ],
            'closes odd bold at the end of the paragraph' => [
                "this is **bold\nand more\n",
                "this is **bold\nand more**\n",
                true,
            ],
            'does not close bold across a blank line' => [
                "**open\n\nnext\n",
                "**open**\n\nnext\n",
                true,
            ],
            'leaves an even bold span alone' => [
                "**closed** and done\n",
                "**closed** and done\n",
                false,
            ],
            'does not treat a single asterisk as bold' => [
                "this is *not closed\n",
                "this is *not closed\n",
                false,
            ],
            'closes one obvious backtick' => [
                "Call `repair now\n",
                "Call `repair now`\n",
                true,
            ],
            'closes a backtick that opens at the start of the line' => [
                "`repair now\n",
                "`repair now`\n",
                true,
            ],
            'does not close a trailing backtick with no span' => [
                "A lone backtick `\n",
                "A lone backtick `\n",
                false,
            ],
            'does not close a backtick glued inside a word' => [
                "foo`bar\n",
                "foo`bar\n",
                false,
            ],
            'leaves a paired backtick span alone' => [
                "Call `repair` now\n",
                "Call `repair` now\n",
                false,
            ],
            'does not count bold markers inside a paired code span' => [
                "see `**not bold` here\n",
                "see `**not bold` here\n",
                false,
            ],
            'replaces curly quotes outside fences' => [
                "He said \u{201C}hello\u{201D} and \u{2018}bye\u{2019}.\n",
                "He said \"hello\" and 'bye'.\n",
                true,
            ],
            'leaves curly quotes inside a fence' => [
                "```\n\u{201C}quoted\u{201D}\n```\n",
                "```\n\u{201C}quoted\u{201D}\n```\n",
                false,
            ],
            'removes hidden bidi and zero-width characters' => [
                'A' . $hidden . "ب،؟\n",
                "Aب،؟\n",
                true,
            ],
            'removes hidden characters inside a fence' => [
                "```\nsafe\u{202E}text\u{200B}\n```\n",
                "```\nsafetext\n```\n",
                true,
            ],
            'converts bullet characters into markdown lists' => [
                "\u{2022} first\n\u{25CF}second\n",
                "- first\n- second\n",
                true,
            ],
            'converts Arabic-Indic list numbers and keeps the value' => [
                "١. الأول\n١٢. البند الثاني\n",
                "1. الأول\n12. البند الثاني\n",
                true,
            ],
            'does not rewrite an Arabic-Indic decimal' => [
                "١.٢ stays\n",
                "١.٢ stays\n",
                false,
            ],
            'does not rewrite Eastern Arabic-Indic digits' => [
                "۱. بند\n",
                "۱. بند\n",
                false,
            ],
            'strips script iframe object embed and event handlers' => [
                "Hello\n<script>\nalert(1)\n</script>\n<p onclick=\"alert(1)\">Hi</p>\n<iframe src=\"https://evil.test\"></iframe>\n<object data=\"x\"></object>\n<embed src=\"y\">\n",
                "Hello\n\n<p>Hi</p>\n\n\n\n",
                true,
            ],
            'keeps an ordinary html paragraph' => [
                "<p>Hello</p>\n",
                "<p>Hello</p>\n",
                false,
            ],
            'keeps script examples inside a fence' => [
                "```html\n<script>alert(1)</script>\n```\n",
                "```html\n<script>alert(1)</script>\n```\n",
                false,
            ],
            'keeps arabic bold and repairs the bullet beside it' => [
                "• مراجعة **كلمة** في النص\n",
                "- مراجعة **كلمة** في النص\n",
                true,
            ],
            'keeps a closed arabic bold word' => [
                "فقرة عربية فيها **كلمة** وما زالت سليمة.\n",
                "فقرة عربية فيها **كلمة** وما زالت سليمة.\n",
                false,
            ],
            'closes odd arabic bold at the end of the paragraph' => [
                "النص **كلمة في سطر\n",
                "النص **كلمة في سطر**\n",
                true,
            ],
            'keeps arabic prose, comma, question mark, bold, and repairs the bullet' => [
                "• سؤال؟\nالنص فيه **كلمة**، وعلامة ؟ تبقى.\n",
                "- سؤال؟\nالنص فيه **كلمة**، وعلامة ؟ تبقى.\n",
                true,
            ],
            'leaves footnotes untouched' => [
                "A note[^1]\n\n[^1]: The source.\n",
                "A note[^1]\n\n[^1]: The source.\n",
                false,
            ],
            'strips a script tag rebuilt from split fragments' => [
                "<scr<script>ipt>alert(1)</scr<script>ipt>\n",
                "\n",
                true,
            ],
            'strips an event handler after a slash' => [
                "<svg/onload=alert(1)>\n<img src=x/onerror=alert(1)>\n",
                "<svg>\n<img src=x>\n",
                true,
            ],
            'strips an event handler after a quoted > in another attribute' => [
                "<p title=\"a>b\" onclick=\"x\">Hi</p>\n",
                "<p title=\"a>b\">Hi</p>\n",
                true,
            ],
            'repairs a table inside a blockquote' => [
                "> | A | B |\n> | 1 |\n",
                "> | A | B |\n> | --- | --- |\n> | 1 | |\n",
                true,
            ],
            'does not append bold to a table row' => [
                "| A | B |\n| **x | y |\n",
                "| A | B |\n| --- | --- |\n| **x | y |\n",
                true,
            ],
            'does not treat a thematic break as bold' => [
                "***\ntext\n",
                "***\ntext\n",
                false,
            ],
            'leaves english prose untouched' => [
                "Hello, this is plain prose.\n",
                "Hello, this is plain prose.\n",
                false,
            ],
        ];
    }
}
