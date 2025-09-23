<?php

return [
  /*
    |--------------------------------------------------------------------------
    | Log Viewer Domain
    |--------------------------------------------------------------------------
    | You may change the domain where Log Viewer should be active.
    | If the domain is empty, all domains will be valid.
    |
    */

  'route_domain' => null,

  /*
    |--------------------------------------------------------------------------
    | Log Viewer Route
    |--------------------------------------------------------------------------
    | Log Viewer will be available under this URL.
    |
    */

  'route_path' => '/programming/development/log-viewer',

  /*
    |--------------------------------------------------------------------------
    | Back to system URL
    |--------------------------------------------------------------------------
    | When set, displays a link to easily get back to this URL.
    | Set to `null` to hide this link.
    |
    | Optional label to display for the above URL.
    |
    */

  'back_to_system_url' => config('app.url', null),

  'back_to_system_label' => null, // Displayed by default: "Back to {{ app.name }}"

  /*
    |--------------------------------------------------------------------------
    | Log Viewer route middleware.
    |--------------------------------------------------------------------------
    | The middleware should enable session and cookies support in order for the Log Viewer to work.
    | The 'web' middleware will be applied automatically if empty.
    |
    */

  'middleware' => ['web', 'auth', 'role:admin'],

  /*
    |--------------------------------------------------------------------------
    | Include file patterns
    |--------------------------------------------------------------------------
    |
    */

  'include_files' => ['*.log'],

  /*
    |--------------------------------------------------------------------------
    | Exclude file patterns.
    |--------------------------------------------------------------------------
    | This will take precedence over included files.
    |
    */

  'exclude_files' => [
    //'my_secret.log'
  ],

  /*
    |--------------------------------------------------------------------------
    |  Shorter stack trace filters.
    |--------------------------------------------------------------------------
    | Lines containing any of these strings will be excluded from the full log.
    | This setting is only active when the function is enabled via the user interface.
    |
    */

  'shorter_stack_trace_excludes' => [
    '/vendor/symfony/',
    '/vendor/laravel/framework/',
    '/vendor/barryvdh/laravel-debugbar/',
  ],

  /*
    |--------------------------------------------------------------------------
    | Log matching patterns
    |--------------------------------------------------------------------------
    | Regexes for matching log files
    |
    */

  'patterns' => [
    'laravel' => [
      'log_matching_regex' => '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?(\d{6}([\+-]\d\d:\d\d)?)?)\].*/',

      /**
       * This pattern, used for processing Laravel logs, returns these results:
       * $matches[0] - the full log line being tested.
       * $matches[1] - full timestamp between the square brackets (includes microseconds and timezone offset)
       * $matches[2] - timestamp microseconds, if available
       * $matches[3] - timestamp timezone offset, if available
       * $matches[4] - contents between timestamp and the severity level
       * $matches[5] - environment (local, production, etc)
       * $matches[6] - log severity (info, debug, error, etc)
       * $matches[7] - the log text, the rest of the text.
       */
      'log_parsing_regex' =>
        '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}\.?(\d{6}([\+-]\d\d:\d\d)?)?)\](.*?(\w+)\.|.*?)(' .
        implode('|', ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']) .
        ')?: (.*?)( in [\/].*?:[0-9]+)?$/is',
    ],
  ],

  /*
    |--------------------------------------------------------------------------
    | Log Viewer API middleware
    |--------------------------------------------------------------------------
    | The middleware for the Log Viewer API routes.
    |
    */
  'api_middleware' => ['web', 'auth', 'role:admin'],
  /*
    |--------------------------------------------------------------------------
    | Log Viewer API stateful domains
    |--------------------------------------------------------------------------
    | Specify which domains will maintain session state when working with the Log Viewer API.
    |
    */
  'api_stateful_domains' => env('LOG_VIEWER_API_STATEFUL_DOMAINS') ? explode(',', env('LOG_VIEWER_API_STATEFUL_DOMAINS')) : null,
];
