<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'codemirror' => [
        'version' => '6.0.2',
    ],
    '@codemirror/lang-yaml' => [
        'version' => '6.1.3',
    ],
    '@codemirror/lint' => [
        'version' => '6.9.7',
    ],
    '@codemirror/state' => [
        'version' => '6.7.6',
    ],
    '@codemirror/view' => [
        'version' => '6.43.15',
    ],
    '@codemirror/language' => [
        'version' => '6.13.1',
    ],
    '@codemirror/commands' => [
        'version' => '6.8.1',
    ],
    '@codemirror/search' => [
        'version' => '6.5.11',
    ],
    '@codemirror/autocomplete' => [
        'version' => '6.18.6',
    ],
    '@lezer/yaml' => [
        'version' => '1.0.4',
    ],
    '@lezer/common' => [
        'version' => '1.5.3',
    ],
    '@lezer/highlight' => [
        'version' => '1.2.5',
    ],
    '@lezer/lr' => [
        'version' => '1.4.8',
    ],
    'crelt' => [
        'version' => '1.0.7',
    ],
    '@marijn/find-cluster-break' => [
        'version' => '1.0.4',
    ],
    'style-mod' => [
        'version' => '4.1.4',
    ],
    'w3c-keyname' => [
        'version' => '2.2.8',
    ],
    '@codemirror/streamparser' => [
        'version' => '6.0.0',
    ],
];
