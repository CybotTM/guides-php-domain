<?php

declare(strict_types=1);

namespace T3Docs\GuidesPhpDomain\PhpDomain;

use phpDocumentor\Guides\RestructuredText\Parser\BlockContext;
use Psr\Log\LoggerInterface;
use T3Docs\GuidesPhpDomain\Nodes\MethodNameNode;

use function ctype_alnum;
use function ctype_alpha;
use function is_array;
use function sprintf;
use function str_split;
use function token_get_all;
use function trim;

use const T_WHITESPACE;

class MethodNameService
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    public function getMethodName(BlockContext $blockContext, string $name): MethodNameNode
    {
        $signature = $this->splitSignature($name);
        if ($signature !== null) {
            return new MethodNameNode($signature['name'], $signature['params'], $signature['return']);
        }

        $this->logger->warning(sprintf('Method signature %s in PHP-domain method description is invalid. ', $name), $blockContext->getLoggerInformation());
        return new MethodNameNode($name, [], null);
    }

    /** Whether the text is shaped like a PHP label, which is what a method name has to be. */
    private function isIdentifier(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (!ctype_alpha($text[0]) && $text[0] !== '_') {
            return false;
        }

        foreach (str_split($text) as $character) {
            if (!ctype_alnum($character) && $character !== '_') {
                return false;
            }
        }

        return true;
    }

    /**
     * Splits a signature into its method name, its parameters and its return type.
     *
     * PHP's own lexer does the splitting, which is what makes a comma inside a default value
     * (`array $range = [1, 2]`, `string $glue = ", "`) a part of that parameter rather than a
     * separator, and what keeps every type syntax working — `?string`, `string|array`,
     * `A&B`, `\Vendor\Thing`, variadics, by-reference and promoted properties alike.
     *
     * The lexer is used without `TOKEN_PARSE` on purpose. That flag would run the grammar of
     * the PHP version rendering the documentation, so a manual documenting PHP 8.4 syntax
     * would fail to render on a PHP 8.2 renderer — and it would fail with a `CompileError`,
     * which is not a `ParseError` and would abort the whole run. Lexing carries no such
     * version boundary: the structure below is derived from parentheses and commas only.
     *
     * @return array{name: string, params: list<string>, return: string|null}|null
     *         null when the text is not a method signature
     */
    private function splitSignature(string $signature): array|null
    {
        /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = @token_get_all('<?php function ' . $signature . ' {}');

        $name = '';
        $nameTokens = 0;
        $params = [];
        $parameter = '';
        $return = '';
        $depth = 0;
        $state = 'name';
        $closed = false;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $isWhitespace = is_array($token) && $token[0] === T_WHITESPACE;

            if ($state === 'name') {
                // The opening tag and the `function` keyword this method added itself.
                if ($text === '<?php ' || $text === 'function' || $isWhitespace) {
                    continue;
                }

                if ($text === '(') {
                    $state = 'params';
                    $depth = 1;
                    continue;
                }

                $name .= $text;
                $nameTokens++;
                continue;
            }

            if ($state === 'params') {
                if ($text === '(' || $text === '[' || $text === '{') {
                    $depth++;
                } elseif ($text === ')' || $text === ']' || $text === '}') {
                    $depth--;

                    if ($depth === 0) {
                        if (trim($parameter) !== '') {
                            $params[] = trim($parameter);
                        }

                        $state = 'closed';
                        $closed = true;
                        continue;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $params[] = trim($parameter);
                    $parameter = '';
                    continue;
                }

                $parameter .= $text;
                continue;
            }

            if ($state === 'closed') {
                if ($isWhitespace) {
                    continue;
                }

                if ($text === ':') {
                    $state = 'return';
                    continue;
                }

                // The body this method added itself; anything else does not belong to a signature.
                if ($text === '{') {
                    break;
                }

                return null;
            }

            if ($state === 'return') {
                if ($text === '{') {
                    break;
                }

                $return .= $text;
            }
        }

        $name = trim($name);
        $return = trim($return);

        // Exactly one token, and it has to read like a name. A keyword is allowed: `list()` and
        // `print()` are legal method names and occur in the documentation.
        if (!$closed || $depth !== 0 || $nameTokens !== 1 || !$this->isIdentifier($name)) {
            return null;
        }

        // A colon announces a return type, so an empty one is a broken signature rather than none.
        if ($state === 'return' && $return === '') {
            return null;
        }

        foreach ($params as $parameter) {
            if (trim($parameter) === '') {
                return null;
            }
        }

        return [
            'name' => $name,
            'params' => $params,
            'return' => $return === '' ? null : $return,
        ];
    }
}
