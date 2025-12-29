<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use ReflectionClass;
use Serializor;
use stdClass;
use Tests\Fixtures\A;
use Tests\Fixtures\A3;
use Tests\Fixtures\ObjSelf;
use Tests\Fixtures\ObjTyped;
use Tests\Fixtures\ObjTypedUninit;
use Tests\Fixtures\ObjWithConst;
use Tests\Fixtures\ObjectWithSerialize;
use Tests\Fixtures\Util;

test('non-static closure with simple const', function () {
    $c = function () {
        return ObjWithConst::FOO;
    };

    $u = Serializor::unserialize(Serializor::serialize($c))();

    expect($u)->toBe('bar');
});
test('static closure with simple const', function () {
    $c = static function () {
        return ObjWithConst::FOO;
    };

    $u = Serializor::unserialize(Serializor::serialize($c))();

    expect($u)->toBe('bar');
});

test('closure use return value', function () {
    $a = 100;
    $c = function () use ($a) {
        return $a;
    };

    $u = Util::s($c);

    expect($a)->toEqual($u());
});

test('closure use return closure', function () {
    $a = function ($p) {
        return $p + 1;
    };
    $b = function ($p) use ($a) {
        return $a($p);
    };

    $v = 1;
    $u = Util::s($b);

    expect($u(1))->toEqual($v + 1);
});
test('closure use return closure by ref', function () {
    $a = function ($p) {
        return $p + 1;
    };
    $b = function ($p) use (&$a) {
        return $a($p);
    };

    $v = 1;
    $u = Util::s($b);

    expect($u(1))->toEqual($v + 1);
});

test('closure use self', function () {
    $a = function () use (&$a) {
        return $a;
    };
    $u = Util::s($a);

    expect($u())->toEqual($u);
});

test('closure use self in array', function () {
    $a = [];

    $b = function () use (&$a) {
        return $a[0];
    };

    $a[] = $b;

    $u = Util::s($b);

    expect($u())->toEqual($u);
});
test('closure use self in object', function () {
    $a = new stdClass();

    $b = function () use (&$a) {
        return $a->me;
    };

    $a->me = $b;

    $u = Util::s($b);

    expect($u())->toEqual($u);
});

test('closure use self in multi array', function () {
    $a = [];
    $x = null;

    $b = function () use (&$x) {
        return $x;
    };

    $c = function ($i) use (&$a) {
        $f = $a[$i];

        return $f();
    };

    $a[] = $b;
    $a[] = $c;
    $x = $c;

    $u = Util::s($c);

    expect($u(0))->toEqual($u);
});

test('closure use self in instance', function () {
    $i = new ObjSelf();
    $c = function ($c) use ($i) {
        return $c === $i->o;
    };
    $i->o = $c;
    $u = Util::s($c);
    expect($u($u))->toBeTrue();
});

test('closure use self in instance2', function () {
    $i = new ObjSelf();
    $c = function () use (&$c, $i) {
        return $c == $i->o;
    };
    $i->o = &$c;
    $u = Util::s($c);
    expect($u())->toBeTrue();
});
test('closure serialization twice', function () {
    $a = function ($p) {
        return $p;
    };

    $b = function ($p) use ($a) {
        return $a($p);
    };

    $u = Util::s(Util::s($b));

    expect($u('ok'))->toEqual('ok');
});

test('closure real serialization', function () {
    $f = function ($a, $b) {
        return $a + $b;
    };

    $u = Util::s(Util::s($f));
    expect($u(2, 3))->toEqual(5);
});
test('closure nested', function () {
    $o = function ($a) {
        // this should never happen
        if ($a === false) {
            return false;
        }

        $n = function ($b) {
            return ! $b;
        };

        $ns = Util::s($n);

        return $ns(false);
    };

    $os = Util::s($o);

    expect($os(true))->toEqual(true);
});

test('closure curly syntax', function () {
    $f = function () {
        $x = (object) ['a' => 1, 'b' => 3];
        $b = 'b';

        return $x->{'a'} + $x->{$b};
    };
    $f = Util::s($f);
    expect($f())->toEqual(4);
});

test('closure bind to object', function () {
    $a = new A();

    $b = function () {
        return $this->aPublic();
    };

    $b = $b->bindTo($a, A::class);

    $u = Util::s($b);

    expect($u())->toEqual('public called');
});

test('closure bind to object scope', function () {
    $a = new A();

    $b = function () {
        return $this->aProtected();
    };

    $b = $b->bindTo($a, A::class);

    $u = Util::s($b);

    expect($u())->toEqual('protected called');
});
test('closure bind to object static scope', function () {
    $a = new A();

    $b = function () {
        return static::aStaticProtected();
    };

    $b = $b->bindTo(null, A::class);

    $u = Util::s($b);

    expect($u())->toEqual('static protected called');
});

test('mixed encodings', function () {
    $a = iconv('utf-8', 'utf-16', 'Düsseldorf');
    $b = mb_convert_encoding('Düsseldorf', 'ISO-8859-1', 'UTF-8');

    $closure = function () use ($a, $b) {
        return [$a, $b];
    };

    $u = Util::s($closure);
    $r = $u();

    expect($r[0])->toEqual($a);
    expect($r[1])->toEqual($b);
});

test('rebound closure', function () {
    $closure = Closure::bind(
        function () {
            return $this->hello();
        },
        new A3(function () {
            return 'Hi';
        }),
        A3::class
    );

    $u = Util::s($closure);
    $r = $u();

    expect($r)->toEqual('Hi');
});

test('complex recursion', function () {

    $b = null;
    $a = [&$b];
    $b = function () use ($a) {
        return $a;
    };
    $a[] = &$a;
    $nv = Util::s(function () use (&$b, &$a) {
        $res = $b();
        expect($res[0])->toBe($a[0]);
    });
    $nv();
});

test('recursion maintained', function () {

    $v = 'Hello';
    $a1 = [&$v, &$v, $v, function & () use (&$a1) {
        return $a1;
    }];
    $a2 = Util::s($a1[3])();
    expect($a2[0])->toBe($a2[1]);
    expect($a2[0] === $a2[2])->toBeTrue();
    $a2[0] = 'World';
    expect($a2[0])->toBe($a2[1]);
    expect($a2[0] === $a2[2])->toBeFalse();
});

test('complex typed object', function () {
    $o2 = null;
    $o = new ObjTyped(function () use (&$o2) {}, null);
    $o2 = new ObjTyped(function () use ($o) {
        return $o;
    }, $o);
    $o3 = Util::s($o2);
    expect(($o3->closure)())->toBe($o3->objTyped);
});

test('object with uninitialized property', function () {
    $o = new ObjTypedUninit();
    $o2 = Util::s($o);
    $rc = new ReflectionClass($o);
    $rp = $rc->getProperty('value');
    expect($rp->isInitialized($o2))->toBeFalse();
});

test('object with __serialize/__unserialize containing closure', function () {
    $multiplier = 3;
    $obj = new ObjectWithSerialize('Test', fn($x) => str_repeat($x, $multiplier));

    $restored = Util::s($obj);

    expect($restored->run())->toBe('TestTestTest');
});
