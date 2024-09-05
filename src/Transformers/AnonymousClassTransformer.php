<?php

namespace Serializor\Transformers;

use PhpToken;
use ReflectionObject;
use Serializor\SerializerError;
use Serializor\Stasis;
use Serializor\TransformerInterface;

/**
 * Provides serialization of anonymous classes for Serializor.
 *
 * @package Serializor
 */
class AnonymousClassTransformer implements TransformerInterface
{
    private const STARTING = 0;
    private const CONSTRUCTOR_ARGS = 1;
    private const BEFORE_BODY = 2;
    private const BODY = 4;
    private const CLASS_MEMBER_NAME = 5;
    private const CLASS_MEMBER_BODY = 6;
    private const CLASS_CONSTRUCTOR_ARGS = 7;
    private const DONE = 255;

    private static array $tokenCache = [];
    private static array $functionCache = [];
    private static array $classMakerCache = [];

    public function transforms(mixed $value): bool
    {
        return \is_object($value) && \str_starts_with(\get_class($value), 'class@anonymous');
    }

    public function resolves(Stasis $value): bool
    {
        return $value->getClassName() == 'class@anonymous';
    }

    public function transform(mixed $value): mixed
    {
        if (!$this->transforms($value)) {
            throw new SerializerError("Can't transform " . get_debug_type($value));
            return false;
        }
        $ro = new ReflectionObject($value);

        $frozen = new Stasis('class@anonymous');
        $frozen->p['|hash'] = self::getClassHash($ro);
        $frozen->p['|code'] = self::getCode($ro);
        $parentRo = $ro->getParentClass();
        $frozen->p['|extends'] = $parentRo ? $parentRo->getName() : null;
        $frozen->p['|implements'] = $ro->getInterfaceNames();
        $frozen->p['|props'] = Stasis::getObjectProperties($value);
        return $frozen;
    }

    public function resolve(mixed $value): mixed
    {
        if (!($value instanceof Stasis) || $value->getClassName() !== 'class@anonymous') {
            throw new SerializerError("Can't transform " . get_debug_type($value));
        }

        $hash = $value->p['|hash'];

        if (!isset(self::$classMakerCache[$hash])) {
            $code = 'return static function() {
                return new ' . $value->p['|code'] . ';
            };';
            self::$classMakerCache[$hash] = eval($code);
        }

        $instance = self::$classMakerCache[$hash]();

        Stasis::setObjectProperties($instance, $value->p['|props']);

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
            /** @var PhpToken[] */
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
                if ($stackDepth === 0 && \str_contains(",)}];", $token->text)) {
                    break;
                }
            }

            if ($state === self::STARTING && $token->text === '(') {
                $state = self::CONSTRUCTOR_ARGS;
                $stateChangeToken = $token;
            } elseif ($state === self::CONSTRUCTOR_ARGS && $token->text === ')') {
                $state = self::BEFORE_BODY;
                // remove passed args
                while ($capturedTokens[count($capturedTokens) - 1] !== $stateChangeToken) {
                    array_pop($capturedTokens);
                }
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
                                // remove assignment
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
                    // discard this member
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
