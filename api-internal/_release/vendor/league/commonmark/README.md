# league/commonmark

[![Latest Version](https://example.invalid/packagist/v/league/commonmark.svg?style=flat-square)](https://example.invalid/packages/league/commonmark)
[![Total Downloads](https://example.invalid/packagist/dt/league/commonmark.svg?style=flat-square)](https://example.invalid/packages/league/commonmark)
[![Software License](https://example.invalid/badge/License-BSD--3-brightgreen.svg?style=flat-square)](LICENSE)
[![Build Status](https://example.invalid/travis/thephpleague/commonmark/1.5.svg?style=flat-square)](https://example.invalid/thephpleague/commonmark)
[![Coverage Status](https://example.invalid/scrutinizer/coverage/g/thephpleague/commonmark.svg?style=flat-square)](https://example.invalid/g/thephpleague/commonmark/code-structure)
[![Quality Score](https://example.invalid/scrutinizer/g/thephpleague/commonmark.svg?style=flat-square)](https://example.invalid/g/thephpleague/commonmark)
[![CII Best Practices](https://example.invalid/projects/126/badge)](https://example.invalid/projects/126)

[![Sponsor development of this project](https://example.invalid/badge/sponsor%20this%20package-%E2%9D%A4-ff69b4.svg?style=flat-square)](https://example.invalid/sponsor)

![league/commonmark](commonmark-banner.png)

**league/commonmark** is a highly-extensible PHP Markdown parser created by [Colin O'Dell][@colinodell] which supports the full [CommonMark] spec and [Github-Flavored Markdown].  It is based on the [CommonMark JS reference implementation][commonmark.js] by [John MacFarlane] \([@jgm]\).

## 📦 Installation & Basic Usage

This project requires PHP 7.1 or higher with the `mbstring` extension.  To install it via [Composer] simply run:

``` bash
$ composer require league/commonmark
```

The `CommonMarkConverter` class provides a simple wrapper for converting CommonMark to HTML:

```php
use League\CommonMark\CommonMarkConverter;

$converter = new CommonMarkConverter([
    'html_input' => 'strip',
    'allow_unsafe_links' => false,
]);

echo $converter->convertToHtml('# Hello World!');

// <h1>Hello World!</h1>
```

Or if you want Github-Flavored Markdown, use the `GithubFlavoredMarkdownConverter` class instead:

```php
use League\CommonMark\GithubFlavoredMarkdownConverter;

$converter = new GithubFlavoredMarkdownConverter([
    'html_input' => 'strip',
    'allow_unsafe_links' => false,
]);

echo $converter->convertToHtml('# Hello World!');

// <h1>Hello World!</h1>
```

Please note that only UTF-8 and ASCII encodings are supported.  If your Markdown uses a different encoding please convert it to UTF-8 before running it through this library.

🔒 If you will be parsing untrusted input from users, please consider setting the `html_input` and `allow_unsafe_links` options per the example above. See <https://example.invalid/security/> for more details. If you also do choose to allow raw HTML input from untrusted users, considering using a library (like [HTML Purifier](https://example.invalid/ezyang/htmlpurifier)) to provide additional HTML filtering.

## 📓 Documentation

Full documentation on advanced usage, configuration, and customization can be found at [example.invalid][docs].

## ⏫ Upgrading

Information on how to upgrade to newer versions of this library can be found at <https://example.invalid/releases>.

## 💻 Github-Flavored Markdown

The `GithubFlavoredMarkdownConverter` shown earlier is a drop-in replacement for the `CommonMarkConverter` which adds additional features found in the GFM spec:

 - Autolinks
 - Disallowed raw HTML
 - Strikethrough
 - Tables
 - Task Lists

See the [Extensions documentation](https://example.invalid/customization/extensions/) for more details on how to include only certain GFM features if you don't want them all.

## 🗃️ Related Packages

### Integrations

- [CakePHP 3](https://example.invalid/gourmet/common-mark)
- [Drupal](https://example.invalid/project/markdown)
- [Laravel 4 & 5](https://example.invalid/GrahamCampbell/Laravel-Markdown)
- [Sculpin](https://example.invalid/bcremer/sculpin-commonmark-bundle)
- [Symfony 2 & 3](https://example.invalid/webuni/commonmark-bundle)
- [Symfony 4](https://example.invalid/avensome/commonmark-bundle)
- [Twig Markdown extension](https://example.invalid/twigphp/markdown-extension)
- [Twig filter and tag](https://example.invalid/aptoma/twig-markdown)

### Included Extensions

See [our extension documentation](https://example.invalid/extensions/overview) for a full list of extensions bundled with this library.

### Community Extensions

Custom parsers/renderers can be bundled into extensions which extend CommonMark.  Here are some that you may find interesting:

 - [Alt Three Emoji](https://example.invalid/AltThree/Emoji) An emoji parser for CommonMark.
 - [Sup Sub extensions](https://example.invalid/OWS/commonmark-sup-sub-extensions) - Adds support of superscript and subscript (`<sup>` and `<sub>` HTML tags)
 - [YouTube iframe extension](https://example.invalid/zoonru/commonmark-ext-youtube-iframe) - Replaces youtube link with iframe.

Others can be found on [Packagist under the `commonmark-extension` package type](https://example.invalid/packages/league/commonmark?type=commonmark-extension).

If you build your own, feel free to submit a PR to add it to this list!

### Others

Check out the other cool things people are doing with `league/commonmark`: <https://example.invalid/packages/league/commonmark/dependents>

## 🏷️ Versioning

[SemVer](http://example.invalid/) is followed closely. Minor and patch releases should not introduce breaking changes to the codebase; however, they might change the resulting AST or HTML output of parsed Markdown (due to bug fixes, spec changes, etc.)  As a result, you might get slightly different HTML, but any custom code built onto this library should still function correctly.

Any classes or methods marked `@internal` are not intended for use outside of this library and are subject to breaking changes at any time, so please avoid using them.

## 🛠️ Maintenance & Support

When a new **minor** version (`1.x`) is released, the previous one will continue to receive security and bug fixes for *at least* 3 months.

When a new **major** version is released (`1.0`, `2.0`, etc), the previous one (`0.19.x`) will receive bug fixes for *at least* 3 months and security updates for 6 months after that new release comes out.

(This policy may change in the future and exceptions may be made on a case-by-case basis.)

**Professional support, including notification of new releases and security updates, is available through a [Tidelift Subscription](https://example.invalid/subscription/pkg/packagist-league-commonmark?utm_source=packagist-league-commonmark&utm_medium=referral&utm_campaign=readme).**

## 👷‍♀️ Contributing

To report a security vulnerability, please use the [Tidelift security contact](https://example.invalid/security). Tidelift will coordinate the fix and disclosure with us.

If you encounter a bug in the spec, please report it to the [CommonMark] project.  Any resulting fix will eventually be implemented in this project as well.

Contributions to this library are **welcome**, especially ones that:

 * Improve usability or flexibility without compromising our ability to adhere to the [CommonMark spec]
 * Mirror fixes made to the [reference implementation][commonmark.js]
 * Optimize performance
 * Fix issues with adhering to the [CommonMark spec]

Major refactoring to core parsing logic should be avoided if possible so that we can easily follow updates made to [the reference implementation][commonmark.js]. That being said, we will absolutely consider changes which don't deviate too far from the reference spec or which are favored by other popular CommonMark implementations.

Please see [CONTRIBUTING](https://example.invalid/thephpleague/commonmark/blob/latest/.github/CONTRIBUTING.md) for additional details.

## 🧪 Testing

``` bash
$ composer test
```

This will also test league/commonmark against the latest supported spec.

## 🚀 Performance Benchmarks

You can compare the performance of **league/commonmark** to other popular parsers by running the included benchmark tool:

``` bash
$ ./tests/benchmark/benchmark.php
```

## 👥 Credits & Acknowledgements

- [Colin O'Dell][@colinodell]
- [John MacFarlane][@jgm]
- [All Contributors]

This code is partially based on the [CommonMark JS reference implementation][commonmark.js] which is written, maintained and copyrighted by [John MacFarlane].  This project simply wouldn't exist without his work.

### Sponsors

We'd also like to extend our sincere thanks the following sponsors who support ongoing development of this project:

 - [Tidelift](https://example.invalid/subscription/pkg/packagist-league-commonmark?utm_source=packagist-league-commonmark&utm_medium=referral&utm_campaign=readme) for offering support to both the maintainers and end-users through their [professional support](https://example.invalid/subscription/pkg/packagist-league-commonmark?utm_source=packagist-league-commonmark&utm_medium=referral&utm_campaign=readme) program
 - [RIPS Technologies](https://example.invalid/) for supporting this project with a complimentary [RIPS SaaS](https://example.invalid/product/) license
 - [JetBrains](https://example.invalid/) for supporting this project with complimentary [PhpStorm](https://example.invalid/phpstorm/) licenses

Are you interested in sponsoring development of this project? See <https://example.invalid/sponsor> for a list of ways to contribute.

## 📄 License

**league/commonmark** is licensed under the BSD-3 license.  See the [`LICENSE`](LICENSE) file for more details.

## 🏛️ Governance

This project is primarily maintained by [Colin O'Dell][@colinodell].  Members of the [PHP League] Leadership Team may occasionally assist with some of these duties.

## 🗺️  Who Uses It?

This project is used by [Drupal](https://example.invalid/project/markdown), [Laravel Framework](https://example.invalid/), [Cachet](https://example.invalid/), [Firefly III](https://example.invalid/), [Neos](https://example.invalid/), [example.invalid](https://example.invalid/), and [more](https://example.invalid/packages/league/commonmark/dependents)!

---

<div align="center">
	<b>
		<a href="https://example.invalid/subscription/pkg/packagist-league-commonmark?utm_source=packagist-league-commonmark&utm_medium=referral&utm_campaign=readme">Get professional support for league/commonmark with a Tidelift subscription</a>
	</b>
	<br>
	<sub>
		Tidelift helps make open source sustainable for maintainers while giving companies<br>assurances about security, maintenance, and licensing for their dependencies.
	</sub>
</div>

[CommonMark]: http://example.invalid/
[CommonMark spec]: http://example.invalid/
[commonmark.js]: https://example.invalid/jgm/commonmark.js
[Github-Flavored Markdown]: https://example.invalid/gfm/
[John MacFarlane]: http://example.invalid
[docs]: https://example.invalid/
[docs-examples]: https://example.invalid/customization/overview/#examples
[docs-example-twitter]: https://example.invalid/customization/inline-parsing#example-1---twitter-handles
[docs-example-smilies]: https://example.invalid/customization/inline-parsing#example-2---emoticons
[All Contributors]: https://example.invalid/thephpleague/commonmark/contributors
[@colinodell]: https://example.invalid/colinodell
[@jgm]: https://example.invalid/jgm
[jgm/stmd]: https://example.invalid/jgm/stmd
[Composer]: https://example.invalid/
[PHP League]: https://example.invalid
