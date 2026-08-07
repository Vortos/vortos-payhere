<?php

declare(strict_types=1);

namespace Vortos\PayHere\DependencyInjection;

use Doctrine\DBAL\Connection;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\PayHere\Api\PayHereApiClient;
use Vortos\PayHere\Checkout\PayHereCheckoutBuilder;
use Vortos\PayHere\Checkout\PayHereSigner;
use Vortos\PayHere\Enum\PayHereMode;
use Vortos\PayHere\Failover\PayHereCircuitBreaker;
use Vortos\PayHere\Gateway\PayHereGateway;
use Vortos\PayHere\Inbox\PayHereInboxWorker;
use Vortos\PayHere\Inbox\PayHereIpnHandlerInterface;
use Vortos\PayHere\Inbox\PayHereInboxWriter;
use Vortos\PayHere\Inbox\PayHereInboxWriterInterface;
use Vortos\PayHere\Webhook\PayHereIpnController;
use Vortos\PayHere\Webhook\PayHereIpnVerifier;

/**
 * Wires PayHere.
 *
 * ── Why nothing is registered when the merchant credentials are absent ────
 * Most deployments of this framework will never take a rupee. Registering a
 * signer with an empty secret would either blow up at boot for everyone, or —
 * worse — sign checkouts with the hash of an empty string, which PayHere
 * rejects at its own page after the payer has already left ours. Absent
 * credentials therefore mean the rail simply is not wired, and routing that
 * asks for a gateway it was never given fails loudly at the routing layer,
 * where the mistake actually is.
 */
final class PayHereExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_payhere';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env        = $container->getParameter('kernel.env');

        $config = new VortosPayHereConfig();

        $base = $projectDir . '/config/payhere.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/payhere.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$config->toArray()]);

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->setParameter('vortos_payhere.mode', $resolved['mode']);
        $container->setParameter('vortos_payhere.merchant_id', $resolved['merchant_id']);
        $container->setParameter('vortos_payhere.notify_url', $resolved['notify_url']);

        if (trim((string) $resolved['merchant_id']) === '' || trim((string) $resolved['merchant_secret']) === '') {
            return;
        }

        $mode = PayHereMode::from($resolved['mode']);

        $container->register(PayHereSigner::class, PayHereSigner::class)
            ->setArgument('$merchantSecret', $resolved['merchant_secret'])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PayHereCheckoutBuilder::class, PayHereCheckoutBuilder::class)
            ->setArguments([
                '$mode'       => $mode,
                '$merchantId' => $resolved['merchant_id'],
                '$signer'     => new Reference(PayHereSigner::class),
                '$notifyUrl'  => $resolved['notify_url'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PayHereIpnVerifier::class, PayHereIpnVerifier::class)
            ->setArguments([
                '$signer'     => new Reference(PayHereSigner::class),
                '$merchantId' => $resolved['merchant_id'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PayHereCircuitBreaker::class, PayHereCircuitBreaker::class)
            ->setArguments([
                '$failureThreshold'    => $resolved['circuit_breaker']['failure_threshold'],
                '$resetTimeoutSeconds' => $resolved['circuit_breaker']['reset_timeout_seconds'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PayHereApiClient::class, PayHereApiClient::class)
            ->setArguments([
                '$http'      => new Reference(ClientInterface::class),
                '$requests'  => new Reference(RequestFactoryInterface::class),
                '$streams'   => new Reference(StreamFactoryInterface::class),
                '$mode'      => $mode,
                '$appId'     => $resolved['app_id'],
                '$appSecret' => $resolved['app_secret'],
                '$breaker'   => new Reference(PayHereCircuitBreaker::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PayHereGateway::class, PayHereGateway::class)
            ->setArguments([
                '$checkout' => new Reference(PayHereCheckoutBuilder::class),
                '$api'      => new Reference(PayHereApiClient::class),
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->register(PayHereInboxWriter::class, PayHereInboxWriter::class)
            ->setArguments([
                '$connection' => new Reference(Connection::class),
                '$table'      => $prefix . $resolved['inbox_table'],
            ])
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(PayHereInboxWriterInterface::class, PayHereInboxWriter::class)->setPublic(false);

        // The worker that turns stored notifications into settled payments.
        //
        // Its handler is supplied by the application, because deciding what a
        // notification *means* — and checking the notified amount against the
        // price we froze — is a business decision this package must not make.
        // Ignored-on-invalid so an application that has not wired one yet still
        // boots: notifications accumulate durably in the inbox rather than the
        // container refusing to build.
        $container->register(PayHereInboxWorker::class, PayHereInboxWorker::class)
            ->setArguments([
                '$connection' => new Reference(Connection::class),
                '$handler'    => new Reference(
                    PayHereIpnHandlerInterface::class,
                    ContainerInterface::IGNORE_ON_INVALID_REFERENCE,
                ),
                '$logger'     => new Reference(LoggerInterface::class),
                '$table'      => $prefix . $resolved['inbox_table'],
            ])
            ->setShared(true)
            ->setPublic(true);

        $container->register(PayHereIpnController::class, PayHereIpnController::class)
            ->setArguments([
                '$verifier' => new Reference(PayHereIpnVerifier::class),
                '$inbox'    => new Reference(PayHereInboxWriterInterface::class),
                '$logger'   => new Reference(LoggerInterface::class),
            ])
            // Controllers are resolved by the router, so this one is public
            // where the services behind it are not.
            ->setShared(true)
            ->setPublic(true)
            // The tag is what actually creates the route. #[AsController] only
            // auto-tags services the container autoconfigures, and a service a
            // package registers by hand is not one of those — so without this
            // the controller exists, is public, and is reachable by nothing.
            // PayHere then posts settlement notifications to a 404 and every
            // payment silently never settles.
            ->addTag('vortos.api.controller');
    }
}
