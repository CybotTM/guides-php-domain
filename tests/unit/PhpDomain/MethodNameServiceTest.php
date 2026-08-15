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
use function preg_match;
use function sprintf;

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
            'unbalanced brackets in a default' => ['broken(array $a = [1, 2): void'],
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
    }

    /**
     * @return array<string, array{string}>
     */
    public static function realWorldSignatureProvider(): array
    {
        $lines = file(__DIR__ . '/Fixtures/real-world-signatures.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
