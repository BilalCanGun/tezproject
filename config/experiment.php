<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Participant-side runtime
    |--------------------------------------------------------------------------
    |
    | The kiosk executes Python in the browser with Pyodide. Point the index URL
    | at a locally hosted copy when the lab computer has no internet access.
    | The LSL bridge runs on the same lab computer (see bridge/lsl_bridge.py).
    |
    */

    'pyodide_index_url' => env('PYODIDE_INDEX_URL', 'https://cdn.jsdelivr.net/pyodide/v0.27.2/full/'),

    'bridge_url' => env('LSL_BRIDGE_URL', 'ws://127.0.0.1:8765'),

    'kiosk_token_hours' => (int) env('KIOSK_TOKEN_HOURS', 6),

    'code_run_timeout_seconds' => (int) env('CODE_RUN_TIMEOUT_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Assistant provider credentials
    |--------------------------------------------------------------------------
    |
    | The provider and model used in an experiment are chosen per protocol
    | version from the admin panel and frozen with it; only secrets live here.
    |
    */

    'providers' => [
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],
        'timeout_seconds' => (int) env('ASSISTANT_TIMEOUT_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Protocol defaults
    |--------------------------------------------------------------------------
    |
    | Copied into every new protocol version. Once a version is frozen these
    | values cannot change, so the pilot and main study stay reproducible.
    |
    */

    'protocol_defaults' => [
        'assistant' => [
            'provider' => 'scripted',
            'model' => null,
            'temperature' => 0.2,
            'effort' => 'medium',
            'max_tokens' => 1200,
            'history_turns' => 8,
            'socratic_max_code_lines' => 2,
            'socratic_similarity_threshold' => 0.55,
        ],
        'session' => [
            'eyes_open_seconds' => 60,
            'eyes_closed_seconds' => 60,
            'sync_gesture_taps' => 3,
            'delay_min_days' => 6,
            'delay_max_days' => 8,
            'learning_target_minutes' => 30,
            'session1_target_minutes' => 90,
            'session2_target_minutes' => 45,
            // Kind order inside each session; ties fall back to sort_order, then id.
            // Session 2 opens with unassisted far transfer (primary outcome) so that
            // near transfer, debugging or recall items cannot remind the concept first.
            'task_order' => [
                0 => ['baseline', 'concept_precheck'],
                1 => ['learning', 'near_transfer', 'far_transfer', 'debugging', 'explanation'],
                2 => ['far_transfer', 'near_transfer', 'debugging', 'delayed_recall', 'explanation'],
            ],
        ],
        'stratification' => [
            'low_upper_percent' => 40,
            'medium_upper_percent' => 70,
            'block_sizes' => [3, 6],
        ],
        'screening' => [
            'concept_precheck_exclude_percent' => 75,
        ],
        'sample' => [
            'completers_per_condition' => 20,
            'enrolment_target' => 75,
        ],
        'quality' => [
            'min_eeg_usable_epoch_ratio' => 0.70,
            'min_gaze_valid_ratio' => 0.80,
            'max_wearable_loss_ratio' => 0.10,
            'min_marker_integrity' => 1.0,
            'max_calibration_error_deg' => 1.0,
        ],
        'pilot' => [
            'min_task_completion_rate' => 0.80,
            'far_transfer_floor_percent' => 20,
            'far_transfer_ceiling_percent' => 80,
            'min_direct_full_code_rate' => 0.70,
            'min_direct_request_fulfilment_rate' => 0.90,
            'max_socratic_leak_rate' => 0.05,
            'min_socratic_questions_per_reply' => 1.0,
            'max_planning_violations_per_task' => 1.0,
            'max_form_difference_percent' => 15,
        ],
        'rating' => [
            'double_rating_percent' => 30,
        ],
    ],

];
