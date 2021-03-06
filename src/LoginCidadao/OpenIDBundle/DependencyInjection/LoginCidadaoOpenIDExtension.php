<?php

namespace LoginCidadao\OpenIDBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class LoginCidadaoOpenIDExtension extends Extension implements ExtensionInterface, CompilerPassInterface
{

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container,
            new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');
    }

    public function process(ContainerBuilder $container)
    {
        $definition = $container->getDefinition('oauth2.server');
        $args       = $definition->getArguments();

        $args['oauth2.server.storage'][] = new Reference('oauth2.storage.user_claims');
        $args['oauth2.server.storage'][] = new Reference('oauth2.storage.public_key');

        $args['oauth2.server.grant_types'] = array();

        $args['oauth2.server.response_types'] = array(
            'token' => new Reference('oauth2.response_types.token'),
            'code' => new Reference('oauth2.response_types.code'),
            'id_token' => new Reference('oauth2.response_types.id_token'),
            'id_token token' => new Reference('oauth2.response_types.id_token_token'),
            'code id_token' => new Reference('oauth2.response_types.code_id_token'),
        );
        $definition->setArguments($args);

        if ($container->hasDefinition('gaufrette.jwks_fs_filesystem')) {
            $filesystem = new Reference('gaufrette.jwks_fs_filesystem');
            $fileName   = $container->getParameter('jwks_private_key_file');
            $container->getDefinition('oauth2.storage.public_key')
                ->addMethodCall('setFilesystem', array($filesystem, $fileName));
        }

        if ($container->hasDefinition('oauth2.grant_type.authorization_code')) {
            $sessionState = new Reference('oidc.storage.session_state');
            $container->getDefinition('oauth2.grant_type.authorization_code')
                ->addMethodCall('setSessionStateStorage', array($sessionState));
        }
        if ($container->hasDefinition('oauth2.storage.authorization_code')) {
            $sessionState = new Reference('oidc.storage.session_state');
            $container->getDefinition('oauth2.storage.authorization_code')
                ->addMethodCall('setSessionStateStorage', array($sessionState));
        }

        if ($container->hasDefinition('oauth2.scope_manager')) {
            $scopes = $container->getParameter('lc_supported_scopes');
            $container->getDefinition('oauth2.scope_manager')
                ->addMethodCall('setScopes', array($scopes));
        }

        if ($container->hasDefinition('oauth2.storage.access_token')) {
            $secret = $container->getParameter('secret');
            $container->getDefinition('oauth2.storage.access_token')
                ->addMethodCall('setPairwiseSubjectIdSalt', array($secret));
        }
    }
}
