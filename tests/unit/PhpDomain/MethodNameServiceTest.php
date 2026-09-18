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
            'square bracket closed by a parenthesis in a default' => ['broken(array $a = [1, 2)): void'],
            'shaped array return type closed by a square bracket' => ['broken(int $a): array{name: string]'],
            'unbalanced angle bracket in a return type' => ['broken(): array<int, string'],
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

            // A value is not a type, outside an array shape where it is a key.
            'number instead of a return type' => ['broken(): 5'],
            'string literal instead of a return type' => ['broken(): "str"'],
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
            'parameter type that is not valid UTF-8' => ["broken(): str\xFFing"],
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
            preg_match('/^\w+$/', $node->getName()),
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
