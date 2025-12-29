<?php

declare(strict_types=1);

namespace Serializor;

use PhpToken;
use ReflectionClass;
use ReflectionObject;

/**
 * Stasis for anonymous class instances.
 * Extracts and stores the class definition source code.
 */
final class AnonymousClassStasis extends Stasis
{
    private const STARTING = 0;
    private const CONSTRUCTOR_ARGS = 1;
    private const BEFORE_BODY = 2;
    private const BODY = 4;
    private const CLASS_MEMBER_NAME = 5;
    private const CLASS_MEMBER_BODY = 6;
    private const CLASS_CONSTRUCTOR_ARGS = 7;
    private const DONE = 255;

    private string $hash;
    private string $code;
    private ?string $extends = null;
    private array $implements = [];
    private array $props = [];

    private static array $tokenCache = [];
    private static array $functionCache = [];
    private static array $classMakerCache = [];

    private function __construct() {}

    public function __serialize(): array
    {
        $data = ['h' => $this->hash, 'c' => $this->code, 'p' => $this->props];
        if ($this->extends !== null) {
            $data['e'] = $this->extends;
        }
        if (!empty($this->implements)) {
            $data['i'] = $this->implements;
        }
        return $data;
    }

    public function __unserialize(array $data): void
    {
        $this->hash = $data['h'];
        $this->code = $data['c'];
        $this->props = $data['p'];
        $this->extends = $data['e'] ?? null;
        $this->implements = $data['i'] ?? [];
    }

    public function getClassName(): string
    {
        return 'class@anonymous';
    }

    public static function fromObject(object $value, ReflectionClass $rc): AnonymousClassStasis
    {
        $ro = new ReflectionObject($value);
        $frozen = new AnonymousClassStasis();

        $parentRo = $ro->getParentClass();
        $frozen->extends = $parentRo ? $parentRo->getName() : null;
        $frozen->implements = $ro->getInterfaceNames();

        $frozen->hash = self::getClassHash($ro);
        $frozen->code = self::getCode($ro, ['__construct'], $frozen->extends, $frozen->implements);
        $frozen->props = Stasis::getObjectProperties($value);

        return $frozen;
    }

    /**
     * Get the properties for transformation.
     */
    public function &getProps(): array
    {
        return $this->props;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }

        if (!isset(self::$classMakerCache[$this->hash])) {
            $code = 'return static function() {
                return new ' . $this->code . ';
            };';
            self::$classMakerCache[$this->hash] = eval($code);
        }

        $instance = self::$classMakerCache[$this->hash]();

        // Resolve any remaining Stasis objects in props before setting typed properties.
        // This handles circular references where the reference chain update didn't complete
        // before getInstance() was called.
        foreach ($this->props as $k => $v) {
            if ($v instanceof Stasis) {
                $this->props[$k] = $v->getInstance();
            }
        }

        Stasis::setObjectProperties($instance, $this->props);

        $this->setInstance($instance);
        return $instance;
    }

    private static function getCode(ReflectionObject $ro, array $discardMembers = ['__construct'], ?string $expectedExtends = null, array $expectedImplements = []): string
    {
        $hash = self::getClassHash($ro);
        if (isset(self::$functionCache[$hash])) {
            return self::$functionCache[$hash]['code'];
        }
        $sourceFile = $ro->getFileName();
        if (isset(self::$tokenCache[$sourceFile])) {
            $tokens = self::$tokenCache[$sourceFile];
        } else {
            $tokens = self::$tokenCache[$sourceFile] = PhpToken::tokenize(file_get_contents($sourceFile));
        }

        // Count anonymous classes on the same line for ambiguity detection
        $classesOnLine = 0;
        foreach ($tokens as $t) {
            if ($t->line === $ro->getStartLine() && $t->id === T_CLASS) {
                $classesOnLine++;
            }
        }

        $constructorMembers = [];
        $currentConstructorMemberToken = null;

        $capture = false;
        $capturedTokens = [];
        $stackDepth = 0;
        $state = self::STARTING;
        $stateChangeToken = null;
        $memberNameToken = null;
        $stack = [];

        // For disambiguation: track extends/implements found in source
        $foundExtends = null;
        $foundImplements = [];
        $collectingExtends = false;
        $collectingImplements = false;
        $matchCount = 0;  // Count classes that match extends/implements

        foreach ($tokens as $token) {
            if (!$capture) {
                if ($token->line === $ro->getStartLine() && $token->id === T_CLASS) {
                    $capture = true;
                    // Reset disambiguation tracking
                    $foundExtends = null;
                    $foundImplements = [];
                    $collectingExtends = false;
                    $collectingImplements = false;
                } else {
                    continue;
                }
            }
            if (!$token->isIgnorable()) {
                if ($stackDepth === 0 && $state !== self::STARTING && $state !== self::BEFORE_BODY && \str_contains(",)}];", $token->text)) {
                    break;
                }
            }

            // Track extends/implements in STARTING and BEFORE_BODY states
            if ($state === self::STARTING || $state === self::CONSTRUCTOR_ARGS || $state === self::BEFORE_BODY) {
                $isNameToken = $token->id === T_STRING
                    || $token->id === T_NAME_QUALIFIED
                    || $token->id === T_NAME_FULLY_QUALIFIED;

                if ($token->id === T_EXTENDS) {
                    $collectingExtends = true;
                    $collectingImplements = false;
                } elseif ($token->id === T_IMPLEMENTS) {
                    $collectingImplements = true;
                    $collectingExtends = false;
                } elseif ($collectingExtends && $isNameToken) {
                    $foundExtends = ltrim($token->text, '\\');
                    $collectingExtends = false;
                } elseif ($collectingImplements && $isNameToken) {
                    $foundImplements[] = ltrim($token->text, '\\');
                }
            }

            if ($state === self::STARTING && $token->text === '(') {
                $state = self::CONSTRUCTOR_ARGS;
                $stateChangeToken = $token;
            } elseif ($state === self::CONSTRUCTOR_ARGS && $token->text === ')') {
                $state = self::BEFORE_BODY;
                while ($capturedTokens[count($capturedTokens) - 1] !== $stateChangeToken) {
                    array_pop($capturedTokens);
                }
                $stateChangeToken = $token;
            } elseif ($state === self::STARTING && $token->text === '{') {
                $state = self::BODY;
                $stateChangeToken = $token;
            } elseif ($state === self::BEFORE_BODY && $token->text === '{') {
                $state = self::BODY;
                $stateChangeToken = $token;
            } elseif ($state === self::BODY && $stackDepth === 1 && $token->text === '}') {
                $state = self::DONE;
                $stateChangeToken = $token;
            } elseif ($state === self::BODY && $stackDepth === 1) {
                if (!$token->isIgnorable()) {
                    $state = self::CLASS_MEMBER_NAME;
                    $stateChangeToken = $token;
                }
            } elseif ($state === self::CLASS_MEMBER_NAME && $stackDepth === 1) {
                if (!$token->isIgnorable()) {
                    if ($token->text === ';') {
                        $state = self::BODY;
                    } elseif (in_array($token->text, ['=', '{'])) {
                        $state = self::CLASS_MEMBER_BODY;
                    } elseif ($token->text === '(' && $memberNameToken?->text === '__construct') {
                        $state = self::CLASS_CONSTRUCTOR_ARGS;
                    } else {
                        $memberNameToken = $token;
                    }
                }
            } elseif ($state === self::CLASS_CONSTRUCTOR_ARGS) {
                if (in_array($token->text, [')', ',']) && $stackDepth === 2) {
                    if ($currentConstructorMemberToken !== null) {
                        $tmpTokens = [];
                        do {
                            $topToken = array_pop($capturedTokens);
                            $tmpTokens[] = $topToken;
                            if ($topToken->text === '=') {
                                $tmpTokens = [];
                                $testWhitespace = array_pop($capturedTokens);
                                if (!$testWhitespace->isIgnorable()) {
                                    $capturedTokens[] = $testWhitespace;
                                }
                            }
                        } while ($topToken !== $currentConstructorMemberToken);
                        $currentConstructorMemberToken = null;
                        $constructorMembers[] = array_reverse($tmpTokens);
                    }
                    if ($token->text === ')') {
                        $state = self::CLASS_MEMBER_BODY;
                    }
                } elseif ($currentConstructorMemberToken === null && !$token->isIgnorable()) {
                    if (in_array($token->text, ['public', 'protected', 'private'])) {
                        $currentConstructorMemberToken = $token;
                    }
                }
            } elseif ($state === self::CLASS_MEMBER_BODY) {
                if ($stackDepth === 2 && $token->text === '}') {
                    $state = self::BODY;
                } elseif ($stackDepth === 1 && $token->text === ';') {
                    $state = self::BODY;
                }
            }

            $capturedTokens[] = $token;

            if ($state === self::BODY && $memberNameToken !== null) {
                if (in_array($memberNameToken->text, $discardMembers)) {
                    while ($capturedTokens !== [] && array_pop($capturedTokens) !== $stateChangeToken) {
                    }
                }
                if ($memberNameToken->text === '__construct') {
                    foreach ($constructorMembers as $member) {
                        foreach ($member as $memberToken) {
                            $capturedTokens[] = $memberToken;
                        }
                        $capturedTokens[] = new PhpToken(59, ';');
                    }
                }
                $memberNameToken = null;
            }


            if ($token->text === '{') {
                $stack[$stackDepth++] = '}';
            } elseif ($token->text === '(') {
                $stack[$stackDepth++] = ')';
            } elseif ($token->text === '[') {
                $stack[$stackDepth++] = ']';
            } elseif ($stackDepth > 0 && $stack[$stackDepth - 1] === $token->text) {
                --$stackDepth;
                if ($stackDepth === 0 && $token->text === '}') {
                    // Check if this class matches expected extends/implements
                    $extendsMatch = self::matchesExtends($foundExtends, $expectedExtends);
                    $implementsMatch = self::matchesImplements($foundImplements, $expectedImplements);

                    if ($extendsMatch && $implementsMatch) {
                        $matchCount++;
                        if ($classesOnLine > 1) {
                            // Multiple classes on line - need to check for ambiguity
                            if (!isset($savedTokens)) {
                                // First match - save but keep looking
                                $savedTokens = $capturedTokens;
                                $savedConstructorMembers = $constructorMembers;
                                $capture = false;
                                $capturedTokens = [];
                                $state = self::STARTING;
                                $constructorMembers = [];
                                $currentConstructorMemberToken = null;
                            } else {
                                // Found a SECOND match - ambiguous!
                                throw new SerializerError(
                                    'Cannot serialize anonymous class: multiple anonymous classes found on the same line '
                                    . 'and cannot be disambiguated by extends/implements'
                                );
                            }
                        } else {
                            // Single class on line
                            break;
                        }
                    } elseif ($token->line === $ro->getStartLine()) {
                        // Wrong class on same line, keep looking
                        $capture = false;
                        $capturedTokens = [];
                        $state = self::STARTING;
                        $constructorMembers = [];
                        $currentConstructorMemberToken = null;
                    } else {
                        break;  // Moved past the line
                    }
                }
            }
            if ($state === self::DONE) {
                break;
            }
        }
        // After loop: check if we saved a unique match
        if (empty($capturedTokens) && isset($savedTokens)) {
            // We had exactly one match and checked all classes
            $capturedTokens = $savedTokens;
            $constructorMembers = $savedConstructorMembers;
        }

        if (empty($capturedTokens)) {
            throw new SerializerError(
                'Cannot serialize anonymous class: multiple anonymous classes found on the same line '
                . 'and cannot be disambiguated by extends/implements'
            );
        }

        $codes = [];
        foreach ($capturedTokens as $token) {
            $codes[] = $token->text;
        }

        self::$functionCache[$hash] = [
            'code' => \implode('', $codes)
        ];

        return self::$functionCache[$hash]['code'];
    }

    /**
     * Check if found extends matches expected (handles short vs fully qualified names).
     */
    private static function matchesExtends(?string $found, ?string $expected): bool
    {
        if ($found === null && $expected === null) {
            return true;
        }
        if ($found === null || $expected === null) {
            return false;
        }
        // Compare short names (last part after \)
        $foundShort = substr($found, (int) strrpos($found, '\\') + 1);
        $expectedShort = substr($expected, (int) strrpos($expected, '\\') + 1);
        return $foundShort === $expectedShort;
    }

    /**
     * Check if found implements matches expected interfaces.
     */
    private static function matchesImplements(array $found, array $expected): bool
    {
        if (empty($found) && empty($expected)) {
            return true;
        }
        if (count($found) !== count($expected)) {
            return false;
        }
        // Compare short names
        $foundShort = array_map(fn($n) => substr($n, (int) strrpos($n, '\\') + 1), $found);
        $expectedShort = array_map(fn($n) => substr($n, (int) strrpos($n, '\\') + 1), $expected);
        sort($foundShort);
        sort($expectedShort);
        return $foundShort === $expectedShort;
    }

    private static function getClassHash(ReflectionObject $ro): string
    {
        $pco = $ro->getParentClass();
        $interfaces = $ro->getInterfaceNames();
        sort($interfaces);
        $hash = ($ro->getDocComment() ?: '')
            . ($ro->getFileName() ?: '')
            . ($ro->getStartLine() ?: '')
            . ($ro->getEndLine() ?: '')
            . ($ro->getName())
            . ($ro->getShortName())
            . ($pco ? $pco->getName() : '')
            . implode(',', $interfaces);
        return md5($hash);
    }
}
