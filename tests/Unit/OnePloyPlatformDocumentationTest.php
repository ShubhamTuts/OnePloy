<?php

test('the platform specification preserves customer compatibility and honest status labels', function () {
    $specification = file_get_contents(dirname(__DIR__, 2).'/docs/oneploy/PLATFORM-V1.md');

    expect($specification)
        ->toContain('Customer UI Compatibility Contract')
        ->toContain('Preserve the mature Coolify-derived customer UX and navigation; extend it contextually rather than replacing it.')
        ->toContain('`Team` remains the tenant ownership anchor.')
        ->toContain('SHIPPED')
        ->toContain('PARTIAL')
        ->toContain('PLANNED')
        ->toContain('EXTERNAL BLOCKER')
        ->toContain('not production-ready');
});

test('the roadmap links to the platform specification', function () {
    expect(file_get_contents(dirname(__DIR__, 2).'/docs/oneploy/ROADMAP.md'))
        ->toContain('[OnePloy Platform V1](PLATFORM-V1.md)');
});
