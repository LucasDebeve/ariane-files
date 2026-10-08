<?php

/**
 * Returns the importmap for this application.
 *
 * Every dependency is vendored in assets/lib (no CDN at runtime, strict CSP).
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'path' => './assets/lib/stimulus.js',
    ],
    '@hotwired/turbo' => [
        'path' => './assets/lib/turbo.js',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    'pdfjs-dist' => [
        'path' => './assets/lib/pdfjs/pdf.min.mjs',
    ],
    'altcha' => [
        'path' => './assets/lib/altcha/altcha.js',
    ],
    'altcha/i18n/fr-fr' => [
        'path' => './assets/lib/altcha/fr-fr.js',
    ],
];
