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

        $frozen->hash = self::getClassHash($ro);
        $frozen->code = self::getCode($ro);
        $parentRo = $ro->getParentClass();
        $frozen->extends = $parentRo ? $parentRo->getName() : null;
        $frozen->implements = $ro->getInterfaceNames();
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
        Stasis::setObjectProperties($instance, $this->props);

        $this->setInstance($instance);
        return $instance;
    }

    private static function getCode(ReflectionObject $ro, array $discardMembers = ['__construct']): string
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

        $constructorMembers = [];
        $currentConstructorMemberToken = null;

        $capture = false;
        $capturedTokens = [];
        $stackDepth = 0;
        $state = self::STARTING;
        $stateChangeToken = null;
        $memberNameToken = null;
        $stack = [];
        foreach ($tokens as $token) {
            if (!$capture) {
                if ($token->line === $ro->getStartLine() && $token->id === T_CLASS) {
                    $capture = true;
                } else {
                    continue;
                }
            }
            if (!$token->isIgnorable()) {
                if ($stackDepth === 0 && $state !== self::STARTING && $state !== self::BEFORE_BODY && \str_contains(",)}];", $token->text)) {
                    break;
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
                    if ($token->line !== $ro->getEndLine() && $token->line === $ro->getStartLine()) {
                        $capture = false;
                        $capturedTokens = [];
                    } else {
                        break;
                    }
                }
            }
            if ($state === self::DONE) {
                break;
            }
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

    private static function getClassHash(ReflectionObject $ro): string
    {
        $pco = $ro->getParentClass();
        $hash = ($ro->getDocComment() ?: '')
            . ($ro->getFileName() ?: '')
            . ($ro->getStartLine() ?: '')
            . ($ro->getEndLine() ?: '')
            . ($ro->getName())
            . ($ro->getShortName())
            . ($pco ? $pco->getName() : '');
        return md5($hash);
    }
}
