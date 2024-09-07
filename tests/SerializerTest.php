<?php

declare(strict_types=1);


class ObjTyped
{
    public function __construct(
        public readonly Closure $closure,
        public readonly ?ObjTyped $objTyped
    ) {}
}

class ObjSelf
{
    public $o;
}

class ObjTypedUninit
{
    public Closure $value;
    public readonly Closure $c;
    public function __construct()
    {
        $this->c = function () {};
    }
}

class ObjWithConst
{
    const FOO = 'bar';
}

class A
{
    protected static function aStaticProtected()
    {
        return 'static protected called';
    }

    protected function aProtected()
    {
        return 'protected called';
    }

    public function aPublic()
    {
        return 'public called';
    }
}

class A2
{
    private $phrase = 'Hello, World!';

    private $closure1;

    private $closure2;

    private $closure3;

    public function __construct()
    {
        $this->closure1 = function () {
            return $this->phrase;
        };
        $this->closure2 = function () {
            return $this;
        };
        $this->closure3 = function () {
            $c = $this->closure2;

            return $this === $c();
        };
    }

    public function getPhrase()
    {
        $c = $this->closure1;

        return $c();
    }

    public function getEquality()
    {
        $c = $this->closure3;

        return $c();
    }
}

class A3
{
    private $closure;

    public function __construct($closure)
    {
        $this->closure = $closure;
    }

    public function hello()
    {
        return ($this->closure)();
    }
}


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

    $u = s($c);

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
    $u = s($b);

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
    $u = s($b);

    expect($u(1))->toEqual($v + 1);
});

test('closure use self', function () {
    $a = function () use (&$a) {
        return $a;
    };
    $u = s($a);

    expect($u())->toEqual($u);
});

test('closure use self in array', function () {
    $a = [];

    $b = function () use (&$a) {
        return $a[0];
    };

    $a[] = $b;

    $u = s($b);

    expect($u())->toEqual($u);
});
test('closure use self in object', function () {
    $a = new stdClass();

    $b = function () use (&$a) {
        return $a->me;
    };

    $a->me = $b;

    $u = s($b);

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

    $u = s($c);

    expect($u(0))->toEqual($u);
});

test('closure use self in instance', function () {
    $i = new ObjSelf();
    $c = function ($c) use ($i) {
        return $c === $i->o;
    };
    $i->o = $c;
    $u = s($c);
    expect($u($u))->toBeTrue();
});

test('closure use self in instance2', function () {
    $i = new ObjSelf();
    $c = function () use (&$c, $i) {
        return $c == $i->o;
    };
    $i->o = &$c;
    $u = s($c);
    expect($u())->toBeTrue();
});
test('closure serialization twice', function () {
    $a = function ($p) {
        return $p;
    };

    $b = function ($p) use ($a) {
        return $a($p);
    };

    $u = s(s($b));

    expect($u('ok'))->toEqual('ok');
});

test('closure real serialization', function () {
    $f = function ($a, $b) {
        return $a + $b;
    };

    $u = s(s($f));
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

        $ns = s($n);

        return $ns(false);
    };

    $os = s($o);

    expect($os(true))->toEqual(true);
});

test('closure curly syntax', function () {
    $f = function () {
        $x = (object) ['a' => 1, 'b' => 3];
        $b = 'b';

        return $x->{'a'} + $x->{$b};
    };
    $f = s($f);
    expect($f())->toEqual(4);
});

test('closure bind to object', function () {
    $a = new A();

    $b = function () {
        return $this->aPublic();
    };

    $b = $b->bindTo($a, __NAMESPACE__ . '\\A');

    $u = s($b);

    expect($u())->toEqual('public called');
});

test('closure bind to object scope', function () {
    $a = new A();

    $b = function () {
        return $this->aProtected();
    };

    $b = $b->bindTo($a, __NAMESPACE__ . '\\A');

    $u = s($b);

    expect($u())->toEqual('protected called');
});
test('closure bind to object static scope', function () {
    $a = new A();

    $b = function () {
        return static::aStaticProtected();
    };

    $b = $b->bindTo(null, __NAMESPACE__ . '\\A');

    $u = s($b);

    expect($u())->toEqual('static protected called');
});

test('mixed encodings', function () {
    $a = iconv('utf-8', 'utf-16', 'Düsseldorf');
    $b = mb_convert_encoding('Düsseldorf', 'ISO-8859-1', 'UTF-8');

    $closure = function () use ($a, $b) {
        return [$a, $b];
    };

    $u = s($closure);
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

    $u = s($closure);
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
    $nv = s(function () use (&$b, &$a) {
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
    $a2 = s($a1[3])();
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
    $o3 = s($o2);
    expect(($o3->closure)())->toBe($o3->objTyped);
});

test('object with uninitialized property', function () {
    $o = new ObjTypedUninit();
    $o2 = s($o);
    $rc = new ReflectionClass($o);
    $rp = $rc->getProperty('value');
    expect($rp->isInitialized($o2))->toBeFalse();
});
