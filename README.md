# markdown-mend

`markdown-mend` repairs broken markdown from language models before it is rendered. Markdown parsers read a document; they do not fix it. This library fixes the source, and you then pass that source to whatever renderer you already use.

The repair engine is plain PHP. It does not call a language model and it does not use the network. The same input always produces the same output.

## What you get

`Mend::repair()` returns a string. It can:

- unwrap a document that is wrapped in one `markdown` or `md` fence
- close a code fence that runs off the end of the document
- insert a missing table separator, and pad short rows out to the header width (also inside blockquotes)
- close an odd `**` at the end of its paragraph, and close one obvious backtick on a line
- turn curly quotes into straight quotes, except inside code fences, indented code blocks, and inline code
- delete hidden characters that can disguise or reorder text, including inside fences: U+200B (zero-width space), U+202A to U+202E (bidi embeddings and overrides), U+2066 to U+2069 (bidi isolates), and U+FEFF (byte order mark)
- turn the bullets U+2022 and U+25CF into `- ` lists, and turn Arabic-Indic list numbers (U+0660 to U+0669, for example U+0661 followed by `.`) into `1.`, `2.`, and so on
- remove `script`, `iframe`, `object`, and `embed` tags, and remove `on*` event attributes
- replace `javascript:`, `vbscript:`, and `data:text/html` URLs with `#` in markdown links and images, link reference definitions, autolinks, and `href`, `src`, and similar HTML attributes

What is kept:

- U+200C (zero-width non-joiner), U+200D (zero-width joiner), U+200E (left-to-right mark), and U+200F (right-to-left mark), because emoji sequences, Persian and Urdu word shaping, and mixed-direction text depend on them
- Arabic letters, the Arabic comma (U+060C), and the Arabic question mark (U+061F); bold Arabic words stay bold
- code fences, indented code blocks, and inline code are not restyled (see Limits for the one exception)

```php
use Progineous\Mend\Mend;

$clean = Mend::repair($markdown);
```

## Requirements

- PHP 8.2 or newer
- Composer 2
- Laravel is optional

## Setup

The package is not on Packagist yet, so Composer installs it from GitHub. First install PHP 8.2+ and Composer for your system, then add the package to your project.

### Linux (Debian or Ubuntu)

```bash
sudo apt update
sudo apt install php-cli php-mbstring php-xml unzip curl
# Ubuntu releases with PHP older than 8.2 need a newer PHP package source first.
php -v
curl -sS https://getcomposer.org/installer -o composer-setup.php
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

On Fedora, use `sudo dnf install php-cli php-mbstring php-xml composer`.

### macOS

With [Homebrew](https://brew.sh):

```bash
brew install php composer
php -v
composer --version
```

### Windows

1. Install PHP 8.2 or newer. With [winget](https://learn.microsoft.com/windows/package-manager/) in PowerShell:

   ```powershell
   winget install --id PHP.PHP.8.3 -e
   ```

   Or download a build from [windows.php.net](https://windows.php.net/download/) and add its folder to `PATH`. In `php.ini`, enable the `mbstring` and `openssl` extensions if they are off.

2. Install Composer with the official `Composer-Setup.exe` from [getcomposer.org](https://getcomposer.org/download/). It finds `php.exe` and adds `composer` to `PATH`.

3. Open a new terminal and check:

   ```powershell
   php -v
   composer --version
   ```

### Add the package (all systems)

Run this in your project directory:

```bash
composer config repositories.markdown-mend vcs https://github.com/progineousgb/markdown-mend
composer require progineousgb/markdown-mend:dev-main
```

## Examples

Each pair below is the input, then the string `Mend::repair()` returns.

A fence that never closed.

Before:

````
```
echo 1;
````

After:

````
```
echo 1;
```
````

A table with no separator and a short row.

Before:

```
| Name | Age |
| Ada | 36 |
| Grace |
```

After:

```
| Name | Age |
| --- | --- |
| Ada | 36 |
| Grace | |
```

A bullet character turned into a list, with bold left in place. The same applies to right-to-left text such as Arabic.

Before (here `\u2022` stands for the literal bullet character U+2022):

```
\u2022 Review the **word** in the text
```

After:

```
- Review the **word** in the text
```

A dangerous link neutralized.

Before:

```
[click](javascript:alert(1)) <a href="javascript:alert(1)">x</a>
```

After:

```
[click](#) <a href="#">x</a>
```

## Laravel

On Laravel 12 or 13, require `illuminate/support` (`^12.0 || ^13.0`) in the application. Package discovery registers the service provider and the `Mend` facade. The repair engine itself still runs without Laravel.

```php
use Illuminate\Http\Request;
use Progineous\Mend\Facades\Mend;

public function preview(Request $request)
{
    $clean = Mend::repair((string) $request->input('body', ''));

    return view('preview', ['markdown' => $clean]);
}
```

## Development

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan, level max
```

CI runs both on PHP 8.2 and 8.3.

## Limits

This library does not parse markdown into HTML. It does not fix every ambiguous italic (`*` is left alone). Column layouts and footnotes are left untouched. Table alignment that is already present is left untouched.

Indented code is detected with a simple rule: four spaces or a tab after a blank line, outside a list. Inside a list, indented lines are treated as text. Safety repairs (removing active tags, event attributes, and dangerous URLs) run on all text outside code fences, including indented code and inline code, because a renderer may not read them as code.

Removing active tags, `on*` attributes, and dangerous URLs is not a full security product for untrusted HTML. It strips the obvious active content a model sometimes injects. If you render untrusted HTML, also run the output through a real HTML sanitizer.
