<?php

return array(
    'controllers' => array(
        'factories' => array(
            'AlbumRest\Controller\AlbumRest' => function($sm) {
                $locator = $sm->getServiceLocator();
                $server = $locator->get('ZF\OAuth2\Service\OAuth2Server');
                $provider = $locator->get('ZF\OAuth2\Provider\UserId');
                return new AlbumRest\Controller\AlbumRestController($server, $provider);
            }
        ),
    ),
    // The following section is new` and should be added to your file
    'router' => array(
        'routes' => array(
            'album-rest' => array(
                'type' => 'Segment',
                'options' => array(
                    'route' => '/album-rest[/:id]',
                    'constraints' => array(
                        'id' => '[0-9]+',
                    ),
                    'defaults' => array(
                        'controller' => 'AlbumRest\Controller\AlbumRest',
                    ),
                ),
            ),
        ),
    ),
    'view_manager' => array(//Add this config
        'strategies' => array(
            'ViewJsonStrategy',
        ),
    ),
);
