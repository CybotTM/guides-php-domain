<?php

declare(strict_types=1);

namespace T3Docs\GuidesPhpDomain\PhpDomain;

use phpDocumentor\Guides\RestructuredText\Parser\BlockContext;
use Psr\Log\LoggerInterface;
use T3Docs\GuidesPhpDomain\Nodes\MethodNameNode;

use function array_pop;
use function array_slice;
use function count;
use function in_array;
use function is_array;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function strlen;
use function strtolower;
use function token_get_all;
use function trim;

use const T_CLOSE_TAG;
use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DNUMBER;
use const T_DOC_COMMENT;
use const T_LNUMBER;
use const T_VARIABLE;
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

    /**
     * Whether the text is shaped like a PHP label, which is what a method name has to be.
     *
     * A label is not limited to ASCII — `fooBär()` is a method PHP accepts and a manual can
     * document — so the high range is part of the pattern, as it is in PHP's own grammar.
     *
     * The pattern is UTF-8 aware, which is what makes text that is not valid UTF-8 fail it:
     * `preg_match` returns false there rather than 1. The anchor this name becomes is built by
     * a slugger that throws on such a byte, so the signature has to be a warning here instead.
     */
    private function isIdentifier(string $text): bool
    {
        return preg_match('/^[A-Za-z_\x{80}-\x{10FFFF}][A-Za-z0-9_\x{80}-\x{10FFFF}]*$/u', $text) === 1;
    }

    /**
     * Pops the generic brackets an angle-bracket token closes.
     *
     * `>>` ends two generics but lexes as one token, so `array<int, list<string>>` closes both
     * of its brackets at once. Returns false when the brackets do not match, which leaves the
     * caller to reject the signature rather than repair it.
     *
     * @param list<string> $closers
     */
    private function closeAngleBrackets(string $text, array &$closers): bool
    {
        for ($count = strlen($text); $count > 0; $count--) {
            if (array_pop($closers) !== '>') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the token may appear in a return type.
     *
     * A return type is a closed vocabulary — type names, the operators joining them, and the
     * brackets of PHPStan and Psalm syntax. Everything else is prose, punctuation or code that
     * an author wrote after the signature, and accepting it renders that text as a type.
     *
     * @param int|null $id  the token id, null for a single-character token
     * @param bool $nested  whether the token sits inside a bracket of the type
     */
    private function isReturnTypeToken(string $text, int|null $id, bool $nested): bool
    {
        // `&` and `>>` reach this as named tokens, so operators are matched by text, not by id.
        if (in_array($text, ['?', '|', '&', '-', '(', ')', '[', ']', '{', '}', '<', '>', '>>'], true)) {
            return true;
        }

        // `Foo::BAR` is a type PHPStan understands, in a shape key and on its own.
        if ($text === '::') {
            return true;
        }

        // A shape separates its keys with a comma and names them with a colon, and marks itself
        // unsealed with `...`. Neither has a meaning outside the brackets, where a comma would
        // announce a second type.
        if ($nested && in_array($text, [',', ':', '...'], true)) {
            return true;
        }

        if ($id === null) {
            return false;
        }

        // A parenthesised scalar lexes as a cast, so `callable(int): string` never reaches the
        // bracket stack as brackets. The token is balanced by construction and carries a type.
        if (preg_match('/^\\(\\s*[A-Za-z]+\\s*\\)$/', $text) === 1) {
            return true;
        }

        // `$this` is a type PHPStan understands. Every other variable, and every literal, is a
        // shaped-array key — outside the brackets it is a value where a type was announced.
        if (in_array($id, [T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_VARIABLE], true)) {
            return $nested || $text === '$this';
        }

        // Every other word: a type name, qualified or not, and the keywords a type name lexes
        // into — `array`, `static`, `class`, or the `list` and `empty` inside `non-empty-list`.
        return preg_match('/^\\\\?[A-Za-z_\x{80}-\x{10FFFF}][A-Za-z0-9_\x{80}-\x{10FFFF}\\\\]*$/u', $text) === 1;
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
     * Nothing but the signature is lexed — no body is appended — so a return type carries its
     * own brackets, which PHPStan and Psalm array shapes and generics are written with. Two
     * rules keep the text that follows the type out of it:
     *
     * - A token separated from the type by whitespace does not belong to it, unless it joins
     *   two types (`string | int`) or follows the operator that does (`string|` `int`). That
     *   rejects `string {}`, `array of Item` and `string -- returns the name`, and accepts
     *   `array{name: string}`, whose brace hangs on the type.
     * - A return type is a closed vocabulary, checked per token, so `void;`, `int, string` and
     *   `string{ return $a; }` are rejected rather than rendered as types.
     *
     * Brackets are tracked by kind rather than by depth, in the parameter list as well, so
     * `broken(int $a]` is a warning instead of a parameter list silently closed and repaired.
     *
     * @return array{name: string, params: list<string>, return: string|null}|null
     *         null when the text is not a method signature
     */
    private function splitSignature(string $signature): array|null
    {
        /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = @token_get_all('<?php function ' . $signature);

        // The opening tag and the `function` keyword this method prepended itself. Skipping
        // them by position rather than by text keeps `function()` — a legal method name that
        // lexes as a keyword — parsable, and keeps an author's own `function` a warning.
        $tokens = array_slice($tokens, 2);

        /** @var array<string, string> $openers a bracket and the closer that has to match it */
        $openers = ['(' => ')', '[' => ']', '{' => '}', '#[' => ']', '<' => '>'];

        $name = '';
        $nameTokens = 0;
        $params = [];
        $parameter = '';
        $return = '';
        /** @var list<string> $closers the closers still owed, innermost last */
        $closers = [];
        $state = 'name';
        $afterWhitespace = false;
        $lastReturnToken = '';
        $sawDefault = false;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $isWhitespace = is_array($token) && $token[0] === T_WHITESPACE;

            // A comment carries text the renderer would have to drop, and dropping it silently
            // is how a signature ends up rendered without the half its author hid behind `//`.
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                return null;
            }

            /* A closing tag ends the PHP the lexer was handed, so everything after it arrives
               as one lump of inline HTML. Appending that lump renders a signature nobody wrote.
               The tag itself is always the first of the two tokens, so it is the one to catch. */
            if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                return null;
            }

            $wasAfterWhitespace = $afterWhitespace;
            $afterWhitespace = $isWhitespace;

            if ($state === 'name') {
                if ($isWhitespace) {
                    continue;
                }

                if ($text === '(') {
                    $state = 'params';
                    $closers[] = ')';
                    continue;
                }

                $name .= $text;
                $nameTokens++;
                continue;
            }

            if ($state === 'params') {
                // An angle bracket is a generic in the type of a parameter and a comparison in
                // its default value, and the `=` is what separates the two. Without this, the
                // comma of `array<int, string> $rows` splits the parameter in half.
                if ($text === '=' && count($closers) === 1) {
                    $sawDefault = true;
                }

                if ($text === '<' && !$sawDefault && (count($closers) === 1 || in_array($closers[count($closers) - 1] ?? '', ['>', '}'], true))) {
                    $closers[] = '>';
                    $parameter .= $text;
                    continue;
                }

                if ($text === '>' || $text === '>>') {
                    if (($closers[count($closers) - 1] ?? '') === '>') {
                        if (!$this->closeAngleBrackets($text, $closers)) {
                            return null;
                        }

                        $parameter .= $text;
                        continue;
                    }

                    // At the top level of the list the only `>` that belongs here is a
                    // comparison, which can only stand in a default value. Deeper in, it is
                    // inside an attribute argument or a default, where it is the author's.
                    if (!$sawDefault && count($closers) === 1) {
                        return null;
                    }
                }

                // `#[` is one token, and the `]` closing an attribute is a separate one.
                if ($text !== '<' && isset($openers[$text])) {
                    $closers[] = $openers[$text];
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if (array_pop($closers) !== $text) {
                        return null;
                    }

                    if ($closers === []) {
                        if (trim($parameter) !== '') {
                            $params[] = trim($parameter);
                        }

                        $state = 'closed';
                        continue;
                    }
                } elseif ($text === ',' && count($closers) === 1) {
                    $params[] = trim($parameter);
                    $parameter = '';
                    $sawDefault = false;
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

                // A method body, or any other trailing text, does not belong to a signature.
                return null;
            }

            if ($state === 'return') {
                if ($isWhitespace) {
                    $return .= $text;
                    continue;
                }

                // `callable(int): string` names its own return type, so a colon that follows a
                // closed parameter list belongs to the type even at the top level.
                $callableColon = $text === ':' && $closers === [] && str_ends_with($lastReturnToken, ')');

                // `Foo::*` names every constant of a class. A `*` anywhere else is arithmetic.
                $constantWildcard = $text === '*' && $lastReturnToken === '::';

                if (!$callableColon && !$constantWildcard && !$this->isReturnTypeToken($text, is_array($token) ? $token[0] : null, $closers !== [])) {
                    return null;
                }

                // Whitespace ends the type unless what follows joins two types, or the type
                // ended on the operator that does. `string {}` and `array of Item` stop here;
                // `string | int` and `array{name: string}` do not.
                if (
                    $closers === []
                    && $wasAfterWhitespace
                    && $lastReturnToken !== ''
                    && !in_array($text, ['|', '&'], true)
                    && !in_array($lastReturnToken, ['|', '&', '?', ':'], true)
                    && !$callableColon
                ) {
                    return null;
                }

                // Only `array`, `list` and `object` carry a shape, so a brace after any other
                // type is a method body written without the space that would have stopped it.
                // `non-empty-list{int}` and `non-empty-array{a: int}` end on those same tokens.
                if ($text === '{' && !in_array(strtolower($lastReturnToken), ['array', 'list', 'object'], true)) {
                    return null;
                }

                // A type opens with a name, a `?` or the parenthesis of a DNF type. A bracket
                // or an operator in that position is not a type at all, as in `foo(): {}`.
                if ($lastReturnToken === '' && in_array($text, ['|', '&', '-', '::', ')', '[', ']', '{', '}', '<', '>'], true)) {
                    return null;
                }

                if (($text === '>' || $text === '>>') && !$this->closeAngleBrackets($text, $closers)) {
                    return null;
                }

                if ($text !== '>' && $text !== '>>') {
                    if (isset($openers[$text])) {
                        $closers[] = $openers[$text];
                    } elseif (in_array($text, [')', ']', '}'], true) && array_pop($closers) !== $text) {
                        return null;
                    }
                }

                $lastReturnToken = $text;
                $return .= $text;
            }
        }

        $name = trim($name);
        $return = trim($return);

        // Exactly one token, and it has to read like a name. A keyword is allowed: `list()` and
        // `print()` are legal method names and occur in the documentation.
        if ($state === 'name' || $state === 'params' || $closers !== [] || $nameTokens !== 1 || !$this->isIdentifier($name)) {
            return null;
        }

        // A colon announces a return type, so an empty one is a broken signature rather than none.
        // A type that ends on an operator is the same thing half written — `string|` names one
        // type and promises another, and rendering it is how a truncation looks on the page.
        if ($state === 'return' && ($return === '' || in_array($lastReturnToken, ['?', '|', '&', '-', ':', '::'], true))) {
            return null;
        }

        foreach ($params as $parameter) {
            // An empty parameter, and text the anchor builder downstream cannot encode. The
            // name and the return type are checked by their own patterns; this is the rest.
            if (trim($parameter) === '' || preg_match('//u', $parameter) !== 1) {
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
