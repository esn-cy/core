<?php /** @noinspection PhpUnused */

namespace Drupal\omnia\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\omnia\Mail\EmailInterface;
use Exception;
use ReflectionClass;
use ReflectionException;

class EmailService
{
    protected ConfigFactoryInterface $configFactory;
    protected MailManagerInterface $mailManager;
    protected RendererInterface $renderer;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        MailManagerInterface          $mailManager,
        RendererInterface             $renderer,
        LoggerChannelFactoryInterface $loggerFactory,
    )
    {
        $this->configFactory = $configFactory;
        $this->mailManager = $mailManager;
        $this->renderer = $renderer;
        $this->logger = $loggerFactory->get('omnia');
    }

    /**
     * Renders and dispatches an Email object.
     *
     * @param string $to The recipient's email address.
     * @param EmailInterface $email The instantiated Email object containing the payload data.
     */
    public function send(string $to, EmailInterface $email): void {
        $email->inject($this->configFactory);

        $renderArray = ['#theme' => $email::getKey()];
        foreach ($email->getVariables() as $key => $value) {
            $renderArray['#' . $key] = $value;
        }

        try {
            if (method_exists($this->renderer, 'renderInIsolation')) {
                // Drupal 10.3+
                $htmlBody = $this->renderer->renderInIsolation($renderArray);
            } else {
                // Drupal 9 / <10.3
                /** @noinspection PhpDeprecationInspection */
                $htmlBody = $this->renderer->renderPlain($renderArray);
            }
        } catch (Exception $e) {
            $this->logger->error('Email Send Error: @message', ['@message' => $e->getMessage()]);
            return;
        }

        $params = [
            'subject' => $email->getSubject(),
            'body' => $htmlBody,
            'from_email' => $email->getFromEmail(),
            'from_name' => $email->getFromName(),
        ];

        $this->mailManager->mail($email::getModuleName(), $email::getKey(), $to, 'en', $params);
        $this->logger->info('Email Send Successfully to @email', ['@email' => $to]);
    }

    /**
     * Helper method to process hook_mail implementations.
     *
     * Abstracts away all boilerplate for setting 'From' headers, Content-Type, MIME versions, and applying the body
     * markup.
     *
     * @param array $message The message array provided by hook_mail().
     * @param array $params The parameters array passed to the mail manager.
     */
    public static function processHookMail(array &$message, array $params): void {
        if (!empty($params['from_email'])) {
            $from = !empty($params['from_name'])
                ? "{$params['from_name']} <{$params['from_email']}>"
                : $params['from_email'];

            $message['from'] = $from;
            $message['headers']['From'] = $from;
        }
        $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
        $message['headers']['MIME-Version'] = '1.0';

        $message['subject'] = $params['subject'] ?? '';

        if (isset($params['body'])) {
            $message['body'][] = Markup::create($params['body']);
        }
    }

    /**
     * Helper method to dynamically generate hook_theme registries.
     *
     * Uses Reflection to instantiate the provided classes without invoking their strict constructors, mapping their
     * getVariables() output to empty strings for the theme registry.
     *
     * @param EmailInterface[] $emailClasses An array of fully qualified class names implementing EmailInterface.
     *
     * @return array An associative array formatted for hook_theme().
     *
     * @throws ReflectionException If a provided class does not exist.
     */
    public static function processHookTheme(array $emailClasses): array {
        $themes = [];
        foreach ($emailClasses as $class) {
            if (is_subclass_of($class, EmailInterface::class)) {
                $reflection = new ReflectionClass($class);
                /** @var EmailInterface $instance */
                $instance = $reflection->newInstanceWithoutConstructor();
                $themes[$class::getKey()] = [
                    'variables' => $instance->getVariables()
                ];
            }
        }
        return $themes;
    }
}