<?php

declare(strict_types=1);

namespace Vortos\PayHere\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $builder = new TreeBuilder('vortos_payhere');
        $root    = $builder->getRootNode();

        $root->children()
            ->enumNode('mode')->values(['sandbox', 'live'])->defaultValue('sandbox')->end()
            ->scalarNode('merchant_id')->defaultValue('')->end()
            ->scalarNode('merchant_secret')->defaultValue('')->end()
            ->scalarNode('app_id')->defaultValue('')->end()
            ->scalarNode('app_secret')->defaultValue('')->end()
            ->scalarNode('notify_url')->defaultValue('')->end()
            ->scalarNode('inbox_table')->defaultValue('payhere_ipn_inbox')->end()
            ->arrayNode('circuit_breaker')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('failure_threshold')->min(1)->defaultValue(5)->end()
                    ->integerNode('reset_timeout_seconds')->min(1)->defaultValue(30)->end()
                ->end()
            ->end()
        ->end();

        return $builder;
    }
}
