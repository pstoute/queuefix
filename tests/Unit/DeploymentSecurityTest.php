<?php

test('the generated https deployment requires secure cookies and hsts', function () {
    $setupScript = file_get_contents(base_path('deploy/setup-server.sh'));

    expect($setupScript)
        ->not->toBeFalse()
        ->toContain("\nSESSION_SECURE_COOKIE=true\n")
        ->toContain('Strict-Transport-Security "max-age=31536000"')
        ->not->toContain('includeSubDomains')
        ->not->toContain('preload');
});
