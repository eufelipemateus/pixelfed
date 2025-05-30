<?php

return [

    'enabled' => env('ANALYTICS_ENABLED', true),

    /**
     * Analytics Dashboard.
     *
     * The prefix and middleware for the analytics dashboard.
     */
    'prefix' => 'i/admin/analytics',

    /**
     * Domain.
     *
     * The domain (optional) for the analytics dashboard.
     */
    'domain' => null,

    'middleware' => [
        'web',
        'admin',
        'dangerzone'
    ],

    /**
     * Exclude.
     *
     * The routes excluded from page view tracking.
     */
    'exclude' => [
        'i/admin/analytics',
        'i/admin/analytics/*',
        '/api/*',
        '/i/admin/*',
    ],

    /**
     * Determine if traffic from robots should be tracked.
     */
    'ignoreRobots' => false,

    /**
     * Ignored IP addresses.
     *
     * The IP addresses excluded from page view tracking.
     */
    'ignoredIPs' => [
        // '192.168.1.1',
    ],

    /**
     * Mask.
     *
     * Mask routes so they are tracked together.
     */
    'mask' => [
        // '/users/*',
    ],

    /**
     * Ignore methods.
     *
     * The HTTP verbs/methods that should be excluded from page view tracking.
     */
    'ignoreMethods' => [
       'OPTIONS', 'POST','DELETE','PUT','PATCH'
    ],

    /**
     * Columns that won't be tracked.
     *
     * List the columns you want to ignore from the page view tracking.
     */
    'ignoredColumns' => [
        // 'source',
        // 'country',
        // 'browser',
        // 'device',
        // 'host',
        // 'utm_source',
        // 'utm_medium',
        // 'utm_campaign',
        // 'utm_term',
        // 'utm_content',
    ],

    'session' => [
        'provider' => \AndreasElia\Analytics\RequestSessionProvider::class,
    ],

    /**
     * Graph.
     *
     * Determine if the analytics graph should be displayed.
     */
    'analyticsGraph' => env('ANALYTICS_GRAPH_ENABLED', true),
];
