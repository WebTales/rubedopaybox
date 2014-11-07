<?php
return array(
    'paymentMeans' => array(
        'paybox' => array(
            'name' => 'Paybox',
            'controller' => 'RubedoPaybox\\Payment\\Controller\\Paybox',
            'definitionFile' => realpath(__DIR__ . '/paymentMeans/') . '/paybox.json',
        ),
    ),
    'controllers' => array(
        'invokables' => array(
            'RubedoPaybox\\Payment\\Controller\\Paybox' => 'RubedoPaybox\\Payment\\Controller\\PayboxController',
        )
    ),
    'templates' => array(
        'namespaces' => array(
            'RubedoPaybox' => realpath(__DIR__ . '/../templates/rubedopaybox')
        ),
    ),
);