<?php

namespace Drupal\omnia\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\omnia\StreamWrapper\StreamWrapperBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles file downloads for a given scheme.
 */
abstract class FileAccessControllerBase extends ControllerBase
{
    abstract function schemeName(): string;
    abstract function streamWrapper(): StreamWrapperBase;

    protected LoggerChannelInterface $logger;

    public function __construct(
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->logger = $loggerFactory->get('omnia');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $loggerFactory
        );
    }

    /**
     * Downloads a file from the declared scheme.
     *
     * @param string $path The path relative to the scheme (e.g. omnia://path).
     *
     * @return Response
     */
    public function download(string $path): Response
    {
        $uri = $this->schemeName() . '://' . $path;

        if (!file_exists($uri)) {
            $realPath = realpath($this->streamWrapper()->getDirectoryPath() . '/' . $path);
            $directoryPath = $this->streamWrapper()->getDirectoryPath();

            $this->logger->warning('FileAccessController: File not found: @uri. Debug Info: Realpath: @real. Dir exists: @dir_exists. Dir writable: @dir_writable. Root: @root', [
                '@uri' => $uri,
                '@real' => $realPath ?: 'FALSE',
                '@dir_exists' => is_dir($directoryPath) ? 'YES' : 'NO',
                '@dir_writable' => is_writable($directoryPath) ? 'YES' : 'NO',
                '@root' => DRUPAL_ROOT
            ]);
            throw new NotFoundHttpException();
        }

        $headers = $this->moduleHandler()->invokeAll('file_download', [$uri]);

        if (empty($headers) || (isset($headers[0]) && $headers[0] === -1)) {
            foreach ($headers as $header) {
                if ($header === -1) {
                    throw new AccessDeniedHttpException();
                }
            }

            if (empty($headers)) {
                $this->logger->debug('FileAccessController: Access denied (no headers returned) for @uri', ['@uri' => $uri]);
                throw new AccessDeniedHttpException();
            }
        }

        return new BinaryFileResponse($uri, 200, $headers);
    }
}
