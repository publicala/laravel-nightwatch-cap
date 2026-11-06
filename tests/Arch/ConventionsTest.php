<?php

declare(strict_types=1);

arch('source classes are final')
    ->expect('Publicala\NightwatchCap')
    ->classes()
    ->toBeFinal();

arch('no debug helpers in source')
    ->expect('Publicala\NightwatchCap')
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'ds']);

arch('source declares strict types')
    ->expect('Publicala\NightwatchCap')
    ->toUseStrictTypes();

arch('namespaced test support declares strict types')
    ->expect('Publicala\NightwatchCap\Tests')
    ->toUseStrictTypes();
