<?php

declare(strict_types=1);

namespace T3Docs\GuidesPhpDomain\Tests\PhpDomain;

use phpDocumentor\Guides\ParserContext;
use phpDocumentor\Guides\RestructuredText\MarkupLanguageParser;
use phpDocumentor\Guides\RestructuredText\Parser\BlockContext;
use phpDocumentor\Guides\RestructuredText\Parser\DocumentParserContext;
use phpDocumentor\Guides\RestructuredText\TextRoles\TextRoleFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use T3Docs\GuidesPhpDomain\PhpDomain\MethodNameService;

use function file;
use function implode;
use function preg_match;
use function preg_replace;
use function sprintf;
use function trim;

use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;

final class MethodNameServiceTest extends TestCase
{
    private LoggerInterface $logger;

    private MethodNameService $service;

    protected function setUp(): void
    {
        $this->logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            /** @param mixed[] $context */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $this->service = new MethodNameService($this->logger);
    }

    /** @return list<string> */
    private function warnings(): array
    {
        // @phpstan-ignore-next-line the spy is an anonymous class with a public message list
        return $this->logger->messages;
    }

    /**
     * @param list<string> $expectedParams
     */
    #[DataProvider('signatureProvider')]
    public function testSignatureIsSplitIntoNameParamsAndReturnType(
        string $signature,
        string $expectedName,
        array $expectedParams,
        string|null $expectedReturn,
    ): void {
        $node = $this->service->getMethodName($this->blockContext(), $signature);

        self::assertSame($expectedName, $node->getName());
        self::assertSame($expectedParams, $node->getParams());
        self::assertSame($expectedReturn, $node->getReturn());
        self::assertSame([], $this->warnings(), 'A valid signature must not warn');
    }

    /**
     * @return array<string, array{string, string, list<string>, string|null}>
     */
    public static function signatureProvider(): array
    {
        return [
            // Shapes that already worked and have to keep working exactly as they did.
            'plain' => ['calculateChecksum(string $plaintext): string', 'calculateChecksum', ['string $plaintext'], 'string'],
            'no parameters' => ['clearCache(): void', 'clearCache', [], 'void'],
            'no return type' => ['configure(array $config)', 'configure', ['array $config'], null],
            'several parameters' => [
                '__construct(ExtensionConfiguration $extensionConfiguration, LogManager $logManager)',
                '__construct',
                ['ExtensionConfiguration $extensionConfiguration', 'LogManager $logManager'],
                null,
            ],
            'nullable parameter with default' => [
                'chat(array $messages, ?ChatOptions $options = null): CompletionResponse',
                'chat',
                ['array $messages', '?ChatOptions $options = null'],
                'CompletionResponse',
            ],
            'untyped parameters' => [
                'arc(PointInterface $center, BoxInterface $size, $start, $end, Color $color)',
                'arc',
                ['PointInterface $center', 'BoxInterface $size', '$start', '$end', 'Color $color'],
                null,
            ],
            'array default' => [
                'analyzeImage(array $content, array $options = []): VisionResponse',
                'analyzeImage',
                ['array $content', 'array $options = []'],
                'VisionResponse',
            ],

            // Union return type — four pages in the wild render broken because of this.
            'union return type' => [
                'generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array',
                'generateAltText',
                ['string|array $imageUrl', '?VisionOptions $options = null'],
                'string|array',
            ],
            'nullable return type' => ['retrieve(string $id): ?string', 'retrieve', ['string $id'], '?string'],
            'intersection return type' => ['all(): Countable&Traversable', 'all', [], 'Countable&Traversable'],
            'fully qualified return type' => [
                'find(int $uid): \\Vendor\\Package\\Thing',
                'find',
                ['int $uid'],
                '\\Vendor\\Package\\Thing',
            ],

            // A comma inside a default value is not a parameter separator.
            'comma inside an array default' => [
                'paginate(array $range = [1, 2], int $page = 1): array',
                'paginate',
                ['array $range = [1, 2]', 'int $page = 1'],
                'array',
            ],
            'comma inside a string default' => [
                'join(string $glue = ", ", array $parts = []): string',
                'join',
                ['string $glue = ", "', 'array $parts = []'],
                'string',
            ],

            // Modern parameter syntax, which the renderer's own PHP version must not decide about.
            'variadic' => ['write(string ...$parts): void', 'write', ['string ...$parts'], 'void'],
            'by reference' => ['sort(array &$rows): void', 'sort', ['array &$rows'], 'void'],
            'promoted property' => [
                '__construct(private readonly string $id)',
                '__construct',
                ['private readonly string $id'],
                null,
            ],
            'asymmetric visibility, PHP 8.4' => [
                '__construct(public private(set) string $id)',
                '__construct',
                ['public private(set) string $id'],
                null,
            ],

            // PHP 8.4 lexes `private(set)` as one token and every version before it as four.
            // It is not a return type under either reading, but it has to be the same answer on
            // both, or a manual renders differently depending on the PHP its renderer runs.
            'asymmetric visibility in the return type' => [
                'get(): private(set)',
                'get',
                [],
                'private(set)',
            ],
            'asymmetric visibility inside a shape' => [
                'get(): array{a: protected(set)}',
                'get',
                [],
                'array{a: protected(set)}',
            ],

            // PHP 8.5 lexes `|>` as one token where earlier versions lex two. Only the `>` in
            // it closes a bracket, so a generic ends where it ends on every version.
            'pipe and angle bracket lexed as one token' => [
                'get(): array<int|>',
                'get',
                [],
                'array<int|>',
            ],
            'pipe with a comparison operator after it' => [
                'get(int |>= $a)',
                'get',
                ['int |>= $a'],
                null,
            ],
            'pipe with a shift-assign operator after it' => [
                'get(int |>>= $a)',
                'get',
                ['int |>>= $a'],
                null,
            ],
            'attribute on a parameter' => [
                'setPassword(#[\\SensitiveParameter] string $password): void',
                'setPassword',
                ['#[\\SensitiveParameter] string $password'],
                'void',
            ],

            // PHPStan and Psalm type syntax, which carries braces and angle brackets of its own.
            'shaped array return type' => [
                'describe(int $uid): array{name: string, size: int}',
                'describe',
                ['int $uid'],
                'array{name: string, size: int}',
            ],
            'shaped array nested in a generic return type' => [
                'all(): array<int, array{name: string}>',
                'all',
                [],
                'array<int, array{name: string}>',
            ],
            'shaped array in a union return type' => [
                'first(): int|array{name: string}',
                'first',
                [],
                'int|array{name: string}',
            ],
            'shaped array as a parameter type' => [
                'store(array{name: string} $row): void',
                'store',
                ['array{name: string} $row'],
                'void',
            ],
            'empty shaped array return type' => ['nothing(): array{}', 'nothing', [], 'array{}'],
            'integer range return type' => ['percent(): int<0, 100>', 'percent', [], 'int<0, 100>'],
            'hyphenated PHPStan return type' => [
                'rows(): non-empty-list<Foo>',
                'rows',
                [],
                'non-empty-list<Foo>',
            ],
            'class string return type' => ['type(): class-string<Foo>', 'type', [], 'class-string<Foo>'],
            'string key in a shaped array return type' => [
                'row(): array{0: string, "key": int}',
                'row',
                [],
                'array{0: string, "key": int}',
            ],
            'this return type' => ['self(): $this', 'self', [], '$this'],
            'DNF return type' => ['get(): (A&B)|null', 'get', [], '(A&B)|null'],

            // A space around `|` or `&` joins two types and is legal PHP.
            'spaced union return type' => ['get(): string | int', 'get', [], 'string | int'],
            'spaced intersection return type' => [
                'all(): Countable & Traversable',
                'all',
                [],
                'Countable & Traversable',
            ],

            // `function` is a legal method name and lexes as a keyword like `list` and `print`.
            'method named function' => ['function(int $a)', 'function', ['int $a'], null],

            // A PHP label is not limited to ASCII, and neither is a method name in a manual.
            'non-ASCII method name' => ['fooBär(int $a): string', 'fooBär', ['int $a'], 'string'],

            // The comma of a generic parameter type does not separate two parameters.
            'generic parameter type' => [
                'store(array<int, string> $rows): void',
                'store',
                ['array<int, string> $rows'],
                'void',
            ],
            'nested generic parameter type' => [
                'store(array<string, array<int, Foo>> $rows, int $page): void',
                'store',
                ['array<string, array<int, Foo>> $rows', 'int $page'],
                'void',
            ],
            'comparison in a default value' => ['check(bool $b = 1 < 2)', 'check', ['bool $b = 1 < 2'], null],

            // `>>` closes two generics but lexes as one token.
            'nested generic return type' => [
                'all(): array<int, array<string, int>>',
                'all',
                [],
                'array<int, array<string, int>>',
            ],

            // A callable type names its own parameters and return type. `(int)` lexes as a cast,
            // so it never reaches the parser as a pair of brackets.
            'callable return type' => ['run(): callable(int): string', 'run', [], 'callable(int): string'],
            'closure return type' => ['run(): Closure(int): void', 'run', [], 'Closure(int): void'],
            'callable return type without parameters' => [
                'run(): callable(): void',
                'run',
                [],
                'callable(): void',
            ],
            'nullable return type written with a space' => ['get(): ? string', 'get', [], '? string'],
            'callable return type with a spaced colon' => [
                'run(): callable(int) : string',
                'run',
                [],
                'callable(int) : string',
            ],

            // Every shape syntax PHPStan documents. `non-empty-list` and `non-empty-array` end
            // on the same token as `list` and `array`, which is what carries the shape.
            'list shape return type' => ['all(): list{int, string}', 'all', [], 'list{int, string}'],
            'object shape return type' => [
                'all(): object{foo: int}',
                'all',
                [],
                'object{foo: int}',
            ],
            'non-empty shape return type' => [
                'all(): non-empty-list{int, string}',
                'all',
                [],
                'non-empty-list{int, string}',
            ],
            'unsealed shape return type' => [
                'all(): array{foo: int, ...}',
                'all',
                [],
                'array{foo: int, ...}',
            ],
            'unsealed shape with typed extra keys' => [
                'all(): array{foo: int, ...<string, int>}',
                'all',
                [],
                'array{foo: int, ...<string, int>}',
            ],
            'class constant as a shape key' => [
                'all(): array{Foo::BAR: int}',
                'all',
                [],
                'array{Foo::BAR: int}',
            ],
            'optional shape key' => [
                'all(): array{foo: int, bar?: string}',
                'all',
                [],
                'array{foo: int, bar?: string}',
            ],
            'object shape intersected with a class' => [
                'all(): object{foo: int}&\\stdClass',
                'all',
                [],
                'object{foo: int}&\\stdClass',
            ],

            // A comparison is only ever a default value, so it is not read as a generic. Inside
            // an attribute argument it is not one either, in either direction.
            'greater-than in a default value' => ['check(bool $b = 2 > 1)', 'check', ['bool $b = 2 > 1'], null],
            'less-than inside an attribute' => [
                'check(#[Attr(1 < 2)] int $a)',
                'check',
                ['#[Attr(1 < 2)] int $a'],
                null,
            ],
            'greater-than inside an attribute' => [
                'check(#[Attr(1 > 2)] int $a)',
                'check',
                ['#[Attr(1 > 2)] int $a'],
                null,
            ],

            // A generic inside a shape, in the position where the type of a parameter is read
            // rather than the return type.
            'generic inside a shaped parameter type' => [
                'store(array{a: list<int>} $x)',
                'store',
                ['array{a: list<int>} $x'],
                null,
            ],
            'generic inside a nested shaped parameter type' => [
                'store(array{a: array{b: list<int>}} $x)',
                'store',
                ['array{a: array{b: list<int>}} $x'],
                null,
            ],
            'generic inside a callable parameter type' => [
                'store(callable(array<int, string>): void $fn)',
                'store',
                ['callable(array<int, string>): void $fn'],
                null,
            ],

            // A class constant is a type of its own, not only a shape key, and `Foo::*` names
            // every constant of a class.
            'class constant return type' => ['all(): Foo::BAR', 'all', [], 'Foo::BAR'],
            'class constant return type on self' => ['all(): self::TYPE', 'all', [], 'self::TYPE'],
            'union of class constants' => [
                'all(): Foo::BAR|Foo::BAZ',
                'all',
                [],
                'Foo::BAR|Foo::BAZ',
            ],
            'class constant wildcard return type' => ['all(): Foo::*', 'all', [], 'Foo::*'],
            'class constant wildcard in a generic' => [
                'all(): value-of<Foo::*>',
                'all',
                [],
                'value-of<Foo::*>',
            ],

            // A trailing comma is legal since PHP 8.0 and announces no further parameter.
            'trailing comma in the parameter list' => ['write(string $part,)', 'write', ['string $part'], null],

            // Type syntax the parser accepts and nothing pinned: the bracket shorthand, a type
            // name outside ASCII, a float and an optional numeric shape key, a negative bound,
            // and the callable forms whose parentheses are real brackets rather than a cast.
            'array shorthand return type' => ['all(): int[]', 'all', [], 'int[]'],
            'array shorthand on a shape' => ['all(): array{a: int}[]', 'all', [], 'array{a: int}[]'],
            'array shorthand on a generic' => ['all(): list<int>[]', 'all', [], 'list<int>[]'],
            'non-ASCII return type' => ['all(): Bär', 'all', [], 'Bär'],
            'float literal return type' => ['all(): 1.5', 'all', [], '1.5'],

            // A literal is a type of its own: a manual writes the set of values a method can
            // return this way. Verified against phpstan/phpdoc-parser 2.3.5, which parses each
            // of these as a type.
            'integer literal return type' => ['all(): 5', 'all', [], '5'],
            'string literal return type' => ["all(): 'foo'", 'all', [], "'foo'"],
            'union of integer literals' => ['all(): 0|1', 'all', [], '0|1'],
            'union of string literals' => ["all(): 'a'|'b'", 'all', [], "'a'|'b'"],
            'literal in a union with a type' => ['all(): int|5', 'all', [], 'int|5'],
            'union of negative and positive literals' => ['all(): -1|0|1', 'all', [], '-1|0|1'],
            'negative literal return type' => ['all(): -1', 'all', [], '-1'],
            'negative float literal return type' => ['all(): -1.5', 'all', [], '-1.5'],
            'nullable negative literal return type' => ['all(): ?-1', 'all', [], '?-1'],
            'positive literal return type' => ['all(): +1', 'all', [], '+1'],
            'union carrying both signs' => ['all(): +1|0|-1', 'all', [], '+1|0|-1'],

            // A constant name may carry the `*` anywhere, and a generic argument may be one.
            'class constant wildcard with a prefix' => [
                'all(): Currency::CURRENCY_*',
                'all',
                [],
                'Currency::CURRENCY_*',
            ],
            'nullable class constant wildcard' => [
                'all(): ?Currency::CURRENCY_*',
                'all',
                [],
                '?Currency::CURRENCY_*',
            ],
            'wildcard as a generic argument' => ['all(): Foo<Bar, *>', 'all', [], 'Foo<Bar, *>'],
            'spaced class constant operator' => ['all(): Foo :: BAR', 'all', [], 'Foo :: BAR'],

            // A callable marks an optional parameter with `=`, and a shape may quote a key that
            // holds a `$`, which the lexer would otherwise read as interpolation.
            'callable return type with an optional parameter' => [
                'run(): callable(int=): void',
                'run',
                [],
                'callable(int=): void',
            ],
            'quoted shape key holding a dollar sign' => [
                'all(): array{"$ref": int}',
                'all',
                [],
                'array{"$ref": int}',
            ],
            'quoted string type holding a dollar sign' => [
                'all(): list<"$ref">',
                'all',
                [],
                'list<"$ref">',
            ],
            'optional numeric shape key' => ['all(): array{0?: string}', 'all', [], 'array{0?: string}'],
            'negative bound in a range' => ['all(): int<-1, 1>', 'all', [], 'int<-1, 1>'],
            'callable return type with several parameters' => [
                'run(): callable(Foo, Bar): void',
                'run',
                [],
                'callable(Foo, Bar): void',
            ],
            'callable return type with a named parameter' => [
                'run(): Closure(int $a): void',
                'run',
                [],
                'Closure(int $a): void',
            ],
            'callable inside a union return type' => [
                'run(): (callable(int): string)|null',
                'run',
                [],
                '(callable(int): string)|null',
            ],

            // Parameter syntax the parser accepts and nothing pinned.
            'assignment inside an attribute before a generic' => [
                'store(#[Attr(x = 1)] array<int, string> $a)',
                'store',
                ['#[Attr(x = 1)] array<int, string> $a'],
                null,
            ],
            'generic after a parameter with a default' => [
                'store(int $a = 1, array<int, string> $b)',
                'store',
                ['int $a = 1', 'array<int, string> $b'],
                null,
            ],
            'variadic by reference' => ['collect(array &...$rows)', 'collect', ['array &...$rows'], null],
            'signature broken across lines' => [
                "wrap(int \$a,\n    string \$b): void",
                'wrap',
                ['int $a', 'string $b'],
                'void',
            ],
        ];
    }

    #[DataProvider('invalidSignatureProvider')]
    public function testInvalidSignatureWarnsAndKeepsTheRawText(string $signature): void
    {
        $node = $this->service->getMethodName($this->blockContext(), $signature);

        self::assertSame($signature, $node->getName(), 'The unparsable text is kept as the name');
        self::assertSame([], $node->getParams());
        self::assertNull($node->getReturn());
        self::assertCount(1, $this->warnings(), 'An invalid signature warns exactly once');
        self::assertStringContainsString($signature, $this->warnings()[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSignatureProvider(): array
    {
        return [
            'no parentheses at all' => ['justAName'],
            'opening parenthesis only' => ['broken(string $id'],
            'closing parenthesis only' => ['broken string $id)'],
            'empty' => [''],
            'only whitespace' => ['   '],
            'colon without a return type' => ['broken(string $id):'],
            'nothing but parentheses' => ['()'],
            'variable instead of a method name' => ['$broken(int $a)'],
            'string literal instead of a method name' => ['"broken"(int $a)'],
            'unbalanced brackets in a default' => ['broken(array $a = [1, 2): void'],

            // A brace separated from the type by whitespace is not signature text.
            'method body' => ['broken(int $a): string {}'],
            'method body without a return type' => ['broken(int $a) {}'],
            'brace detached from the return type' => ['broken(int $a): array {name: string}'],
            'unbalanced brace in a shaped array return type' => ['broken(int $a): array{name: string'],

            // A comment is not signature text either, and silently dropping one would hide
            // whatever the author meant to say with it.
            'trailing line comment' => ['broken(int $a): string // a comment'],
            'trailing block comment' => ['broken(int $a): string /* a comment */'],
            'trailing hash comment' => ['broken(int $a): string # a comment'],
            'comment hiding a parameter' => ['broken(int $a /*, int $b */)'],
            'comment after the parameter list' => ['broken(int $a) // a comment'],
            'comment before the parameter list' => ['broken /* x */ (int $a)'],

            // A bracket is closed by its own kind, so a mismatched one is not silently repaired.
            'parameter list closed by a square bracket' => ['broken(int $a]'],
            'parameter list closed by a brace' => ['broken(int $a}'],
            'stray angle bracket in the parameter list' => ['broken(int $a>): void'],
            'pipe and angle bracket closing no generic' => ['broken(int|> $a)'],
            'pipe and angle bracket followed by an equals sign' => ['broken(): array<int|>='],
            'square bracket closed by a parenthesis in a default' => ['broken(array $a = [1, 2)): void'],
            'shaped array return type closed by a square bracket' => ['broken(int $a): array{name: string]'],
            'return type closing a bracket it never opened' => ['broken(): string)('],

            // Prose, punctuation and a second type after the return type are not return types.
            'sentence after the return type' => ['broken(): string This method renders the page.'],
            'word after the return type' => ['broken(): array of Item'],
            'dashes after the return type' => ['broken(int $a): string -- returns the name'],
            'semicolon after the return type' => ['broken(): void;'],
            'comma separated return types' => ['broken(): int, string'],
            'arrow body' => ['broken(int $a): int => $a * 2'],
            'closing tag after the return type' => ['broken(): string ?> junk'],
            'attached method body' => ['broken(int $a): string{ return $a; }'],
            'attached empty method body' => ['broken(int $a): string{}'],
            'attached body that reads like a shape' => ['broken(int $a): string{a}'],
            'attached body inside a generic' => ['broken(): array<int, string{}>'],

            // The `function` keyword this parser prepends itself is skipped by position, so a
            // second one is the author's text and does not belong to a signature.
            'function keyword before the name' => ['function broken(int $a)'],
            'function keyword after the name' => ['broken function (int $a)'],
            'two words as a name' => ['broken name(int $a)'],
            'nothing but a comma' => ['broken(,)'],
            'bracket instead of a return type' => ['broken():{}'],
            'operator instead of a return type' => ['broken(): |string'],

            // A type that ends on an operator names one type and promises another.
            'return type ending in a union operator' => ['broken(): string|'],
            'return type ending in an intersection operator' => ['broken(): string&'],
            'return type that is only a question mark' => ['broken(): ?'],

            // A variable is not a type, outside an array shape where it can be a key. `$this`
            // is the one PHPStan means as a type, and it is in the valid provider.
            'variable instead of a return type' => ['broken(): $foo'],

            // An unterminated generic is the angle-bracket form of a truncated return type.
            'unbalanced generic return type' => ['broken(): array<int, string'],
            'unbalanced generic parameter type' => ['broken(array<int, string $rows): void'],
            'shaped array return type closed by an angle bracket' => ['broken(): array{a: int>'],

            // A closing tag hands the rest of the text to the lexer as inline HTML, which is
            // not signature text. The anchor builder throws on a name that is not valid UTF-8,
            // so that has to be a warning here rather than an aborted directive later.
            'closing tag inside the parameter list' => ["broken(int \$a ?><b>x</b><?php )"],
            'closing tag after the parameter list' => ["broken(int \$a) ?><b>x</b>"],
            'method name that is not valid UTF-8' => ["broken\xFFname(int \$a)"],
            'return type that is not valid UTF-8' => ["broken(): str\xFFing"],
            'parameter type that is not valid UTF-8' => ["broken(str\xFFing \$a)"],
            'parameter name that is not valid UTF-8' => ["broken(int \$a\xFF)"],

            // A qualified name lexes as one token, so only the shape of a label rejects it.
            'qualified name instead of a method name' => ['Foo\\Bar(int $a)'],

            // Each operator that cannot open a type, and each that cannot end one.
            'intersection operator instead of a return type' => ['broken(): &string'],
            'square bracket instead of a return type' => ['broken(): [int]'],
            'angle bracket instead of a return type' => ['broken(): <int>'],
            'return type ending in a hyphen' => ['broken(): non-'],
            'return type ending in a plus' => ['broken(): int+'],
            'return type ending in a callable colon' => ['broken(): callable():'],
            'return type ending in a class constant operator' => ['broken(): Foo::'],
            'class constant operator instead of a return type' => ['broken(): ::Foo'],
            'multiplication instead of a return type' => ['broken(): int * 2'],
            'multiplication attached to a return type' => ['broken(): int*2'],
            'shaped parameter type closed by an angle bracket' => ['store(array{a: int> $x)'],
            'unbalanced generic inside a shaped parameter type' => ['store(array{a: list<int} $x)'],
            'unbalanced generic with a comma inside a shaped parameter type' => ['store(array{a: list<int, string} $x)'],
            'multiplication between two type names' => ['broken(): int*int'],
            'multiplication after a class constant' => ['broken(): Foo::BAR|int*int'],
            'multiplication inside a generic' => ['broken(): Foo<int*int>'],
            'wildcard suffixed to a generic argument' => ['broken(): Foo<Bar*>'],
            'wildcard after a shape' => ['broken(): array{a: Foo::BAR}*'],
            'minus before a type name' => ['broken(): -string'],
            'plus before a type name' => ['broken(): +string'],
            'sign with no literal after it' => ['broken(): -'],
            'assignment inside a shape' => ['broken(): array{a=int}'],
            'assignment inside a generic' => ['broken(): list<a=int>'],

            // A comma joins the keys of a shape, not two types; a colon needs a callable before it.
            'comma between two return types' => ['broken(): int,string'],
            'colon without a callable parameter list' => ['broken(): string: int'],

            // Each comment kind, and a closing tag with nothing reopening after it.
            'doc comment inside the parameter list' => ['broken(int $a /** d */)'],
            'closing tag with nothing after it' => ['broken(int $a ?>)'],
            'closing tag ending the signature' => ['broken(int $a ?>'],
        ];
    }

    /**
     * Every method signature found in the TYPO3 documentation at the time of writing.
     *
     * The parser may become stricter or more lenient over time, but it must not start
     * rejecting a signature that is in use today.
     */
    #[DataProvider('realWorldSignatureProvider')]
    public function testRealWorldSignatureIsAccepted(string $signature): void
    {
        $node = $this->service->getMethodName($this->blockContext(), $signature);

        self::assertSame([], $this->warnings(), sprintf('"%s" must parse', $signature));
        self::assertSame(
            1,
            preg_match('/^\w+$/u', $node->getName()),
            sprintf('"%s" yields the method name alone, not the whole signature', $signature),
        );

        // Asserting the name alone would pass a silently truncated return type or a dropped
        // parameter, which is the defect class this parser exists to prevent. Reassembling the
        // three parts pins all of them without an expectation hand-written per signature.
        $return = $node->getReturn();
        $reassembled = $node->getName()
            . '(' . implode(', ', $node->getParams()) . ')'
            . ($return === null ? '' : ': ' . $return);

        self::assertSame(
            self::collapseWhitespace($signature),
            self::collapseWhitespace($reassembled),
            sprintf('"%s" must survive the split without losing text', $signature),
        );
    }

    /** Spacing is the parser's to normalise; the text it keeps is not. */
    private static function collapseWhitespace(string $text): string
    {
        $collapsed = preg_replace('/\s*([(),:])\s*/', '$1', trim($text));
        self::assertIsString($collapsed);

        return (string) preg_replace('/\s+/', ' ', $collapsed);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function realWorldSignatureProvider(): array
    {
        $lines = file(__DIR__ . '/Fixtures/real-world-signatures.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Narrows away the `false` that `file()` returns on a missing fixture. PHPUnit already
        // fails the provider without this, but PHPStan does not accept the `foreach` without it.
        self::assertIsArray($lines);

        $cases = [];
        foreach ($lines as $line) {
            $cases[$line] = [$line];
        }

        return $cases;
    }

    private function blockContext(): BlockContext
    {
        $documentParserContext = new DocumentParserContext(
            self::createStub(ParserContext::class),
            self::createStub(TextRoleFactory::class),
            self::createStub(MarkupLanguageParser::class),
        );

        return new BlockContext($documentParserContext, '');
    }
}
