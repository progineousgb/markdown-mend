# markdown-mend

`markdown-mend` repairs broken markdown from language models before it is rendered. Large markdown parsers read a document. They do not fix the document. This library fixes the source, then you pass that source to whatever renderer you already use.

The repair engine is PHP only. It does not call a language model, and it does not use the network. The same input always produces the same output.

## What you get

`Mend::repair()` returns a string. It can:

- unwrap a document that is wrapped in one `markdown` or `md` fence
- close a code fence that runs off the end of the document
- insert a missing table separator, and pad short rows out to the header width
- close an odd `**` at the end of that paragraph, and close one obvious backtick on a line
- turn curly quotes into straight quotes outside code fences
- delete Unicode bidi overrides and zero-width characters, including inside fences
- turn `•` and `●` into `- ` lists, and turn `١.` `٢.` list numbers into `1.` `2.`
- remove `script`, `iframe`, `object`, and `embed` tags, and remove `on*` attributes

Arabic letters, the Arabic comma `،`, and the Arabic question mark `؟` stay. A bold word such as `**كلمة**` stays bold.

```php
use Progineous\Mend\Mend;

$clean = Mend::repair($markdown);
```

## Install

The package is not on Packagist. Install it from GitHub:

```bash
composer config repositories.markdown-mend vcs https://github.com/progineousgb/markdown-mend
composer require progineousgb/markdown-mend:dev-main
```

PHP 8.2 or newer is required. Laravel is not required.

## Examples

Each pair below is input, then the string `Mend::repair()` returns.

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

Arabic bold left in place, and a bullet turned into a list.

Before:

```
• مراجعة **كلمة** في النص
```

After:

```
- مراجعة **كلمة** في النص
```

## Laravel

On Laravel 12 or 13, require `illuminate/support` (`^12.0 || ^13.0`) in the application. Package discovery registers the service provider and the `Mend` facade. The repair engine itself still runs without Laravel.

```php
public function preview(Request $request)
{
    $clean = Mend::repair((string) $request->input('body', ''));
    return view('preview', ['markdown' => $clean]);
}
```

## Limits

This library does not parse markdown into HTML. It does not fix every ambiguous italic (`*` is left alone). Column layouts and footnotes are left untouched. Table alignment that is already present is left untouched.

Removing `script`, `iframe`, `object`, `embed`, and `on*` attributes is not a security product for untrusted HTML blogs. It only strips the obvious active tags a model sometimes injects. Sanitize untrusted HTML with a real HTML sanitizer if you need that guarantee.
