<?php

declare(strict_types=1);

namespace T3Docs\GuidesPhpDomain\PhpDomain;

use phpDocumentor\Guides\RestructuredText\Parser\BlockContext;
use PhpParser\Error as PhpParserError;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Psr\Log\LoggerInterface;
use T3Docs\GuidesPhpDomain\Nodes\MethodNameNode;
use Throwable;

use function count;
use function preg_match;
use function strpos;
use function sprintf;
use function strlen;
use function substr;
use function trim;

/**
 * Splits the text of a `php:method::` directive into its method name, parameters and return type.
 *
 * Two libraries do the reading, in the order of what they can express.
 *
 * `nikic/php-parser` first, pinned to the newest grammar it knows rather than to the PHP running
 * the render. That is the point of using it: its emulative lexer normalises the tokens a PHP
 * release merges — 8.4's `private(set)`, 8.5's `|>` and `(void)` — so a manual reads the same
 * whichever version renders it, without this package tracking the tokenizer itself. It also
 * gives attributes, promoted properties and asymmetric visibility for free, since they are
 * ordinary PHP syntax it already parses.
 *
 * `phpstan/phpdoc-parser` second, for the types PHP has no syntax for and a manual still
 * documents: `array{name: string}`, `list<int>`, `Foo::FOO_*`, `int|-1`. Its `@method` tag
 * parser reads those in both positions, which is why the signature is rewritten into that
 * tag's order before being handed over.
 *
 * Neither is asked to validate. Where both fail the previous contract stands: a warning naming
 * the signature, and the raw text as the method name, so the page still renders.
 */
class MethodNameService
{
    private readonly Parser $phpParser;

    private readonly Lexer $docLexer;

    private readonly PhpDocParser $docParser;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        // The newest grammar this library knows, not the one running the render. A manual
        // documenting PHP 8.4 syntax has to parse on a renderer still on 8.2.
        $this->phpParser = (new ParserFactory())->createForVersion(PhpVersion::getNewestSupported());

        $config = new ParserConfig([]);
        $this->docLexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $this->docParser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
    }

    public function getMethodName(BlockContext $blockContext, string $name): MethodNameNode
    {
        $signature = $this->splitWithPhpParser($name) ?? $this->splitWithPhpDocParser($name);

        if ($signature !== null) {
            return new MethodNameNode($signature['name'], $signature['params'], $signature['return']);
        }

        $this->logger->warning(sprintf('Method signature %s in PHP-domain method description is invalid. ', $name), $blockContext->getLoggerInformation());
        return new MethodNameNode($name, [], null);
    }

    /**
     * Reads the signature as the PHP declaration it usually is.
     *
     * The parameter and return text is cut out of the original string by the offsets the parser
     * records, never printed back from the tree: a pretty-printer normalises spacing and drops
     * comments, and the contract here is to render what the author wrote.
     *
     * @return array{name: string, params: list<string>, return: string|null}|null
     */
    private function splitWithPhpParser(string $signature): array|null
    {
        $source = '<?php function ' . $signature . ' {}';

        try {
            $statements = $this->phpParser->parse($source);
        } catch (PhpParserError) {
            return null;
        }

        if ($statements === null || count($statements) !== 1) {
            return null;
        }

        $function = $statements[0];

        if (!$function instanceof Function_) {
            return null;
        }

        $params = [];

        foreach ($function->params as $param) {
            $text = $this->sliceSource($source, $param->getStartFilePos(), $param->getEndFilePos());

            if ($text === null) {
                return null;
            }

            $params[] = $text;
        }

        $return = null;

        if ($function->returnType !== null) {
            $return = $this->sliceSource($source, $function->returnType->getStartFilePos(), $function->returnType->getEndFilePos());

            if ($return === null) {
                return null;
            }
        }

        return $this->validated($function->name->toString(), $params, $return);
    }

    /**
     * Reads the signature as an `@method` tag, for the types PHP itself has no syntax for.
     *
     * The tag names its return type in front of the method rather than after the parameter
     * list, so `render(int $a): array{x: int}` is handed over as `array{x: int} render(int $a)`.
     * Finding where to cut needs the parenthesis that closes the parameter list, which is the
     * one piece of splitting neither library does.
     *
     * @return array{name: string, params: list<string>, return: string|null}|null
     */
    private function splitWithPhpDocParser(string $signature): array|null
    {
        $closing = $this->closingParenthesis($signature);

        if ($closing === null) {
            return null;
        }

        $head = substr($signature, 0, $closing + 1);
        $tail = trim(substr($signature, $closing + 1));
        $return = '';

        if ($tail !== '') {
            if ($tail[0] !== ':') {
                return null;
            }

            $return = trim(substr($tail, 1));

            if ($return === '') {
                return null;
            }
        }

        $tag = trim($return . ' ' . $head);

        try {
            $tokens = new TokenIterator($this->docLexer->tokenize('/** @method ' . $tag . ' */'));
            $node = $this->docParser->parse($tokens);
        } catch (Throwable) {
            return null;
        }

        if (count($node->children) !== 1) {
            return null;
        }

        $tagNode = $node->getTags()[0] ?? null;

        if ($tagNode === null || !$tagNode->value instanceof MethodTagValueNode) {
            return null;
        }

        $value = $tagNode->value;

        // The tag grammar names the return type in front of the method, so a word standing
        // before the name is read as one and disappears: `broken function (int $a)` parses as
        // the method `function`, dropping `broken`, and `function broken(int $a)` swallows the
        // author's own `function`. In this directive the return type comes after the parameter
        // list, so nothing at all may precede the name.
        if (!$this->isIdentifier($value->methodName) || !$this->nameStartsTheSignature($signature, $closing, $value->methodName)) {
            return null;
        }

        $params = [];

        foreach ($value->parameters as $parameter) {
            // Printed rather than sliced: the tag was rewritten, so offsets into it would not
            // point into the signature the author wrote. Spacing inside a parameter is
            // normalised as a result, which the original order does not do.
            $params[] = trim((string) $parameter);
        }

        return $this->validated($value->methodName, $params, $return === '' ? null : $return);
    }

    /**
     * The split, or null where it carries text the renderer downstream cannot take.
     *
     * Neither library checks this and neither should: it is not about PHP or about types. The
     * anchor a method name becomes is built by a slugger that throws on a byte that is not
     * valid UTF-8, which takes the whole directive off the page — so the same guard is needed
     * whichever parser produced the split.
     *
     * @param list<string> $params
     *
     * @return array{name: string, params: list<string>, return: string|null}|null
     */
    private function validated(string $name, array $params, string|null $return): array|null
    {
        if (!$this->isIdentifier($name)) {
            return null;
        }

        foreach ($params as $param) {
            if (preg_match('//u', $param) !== 1) {
                return null;
            }
        }

        if ($return !== null && preg_match('//u', $return) !== 1) {
            return null;
        }

        return ['name' => $name, 'params' => $params, 'return' => $return];
    }

    /**
     * Whether the name opens the signature, with nothing but itself before the parameter list.
     */
    private function nameStartsTheSignature(string $signature, int $closing, string $name): bool
    {
        $opening = strpos(substr($signature, 0, $closing + 1), '(');

        if ($opening === false) {
            return false;
        }

        return trim(substr($signature, 0, $opening)) === $name;
    }

    /**
     * The offset of the parenthesis closing the first one, ignoring those inside a quoted string.
     */
    private function closingParenthesis(string $signature): int|null
    {
        $depth = 0;
        $opened = false;
        $quote = null;
        $length = strlen($signature);

        for ($index = 0; $index < $length; $index++) {
            $character = $signature[$index];

            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }

            if ($character === '(') {
                $depth++;
                $opened = true;
                continue;
            }

            if ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }

                if ($depth < 0) {
                    return null;
                }
            }
        }

        return $opened ? null : null;
    }

    /**
     * The text between two offsets of the lexed source, as the author wrote it.
     */
    private function sliceSource(string $source, int $start, int $end): string|null
    {
        if ($start < 0 || $end < $start) {
            return null;
        }

        $text = trim(substr($source, $start, $end - $start + 1));

        return $text === '' ? null : $text;
    }

    /** Whether the text is shaped like a PHP label, which is what a method name has to be. */
    private function isIdentifier(string $text): bool
    {
        return preg_match('/^[A-Za-z_\x{80}-\x{10FFFF}][A-Za-z0-9_\x{80}-\x{10FFFF}]*$/u', $text) === 1;
    }
}
