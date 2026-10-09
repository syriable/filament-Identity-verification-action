<?php

declare(strict_types=1);

return [

    'action' => [

        'label' => 'Verify identity',

        'modal' => [

            'heading' => 'Confirm your identity',

            'description' => 'For your security, please confirm your identity before continuing.',

            'form' => [

                'password' => [
                    'label' => 'Current password',
                ],

            ],

            'actions' => [

                'submit' => [
                    'label' => 'Confirm',
                ],

            ],

        ],

    ],

    'errors' => [

        'invalid_credentials' => [
            'default' => 'The verification details you entered are incorrect.',
            'password' => 'The password is incorrect.',
        ],

        'too_many_attempts' => 'Too many attempts. Please try again in :seconds seconds.',

        'unauthenticated' => 'You must be signed in to continue.',

        'method_unavailable' => 'This verification method is not available for your account.',

    ],

    'notifications' => [

        'verification_required' => [
            'title' => 'Identity verification required',
            'body' => 'Your identity verification is missing or has expired. Please try again.',
        ],

    ],

];
