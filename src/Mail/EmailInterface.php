<?php

namespace Drupal\omnia\Mail;

use Drupal\Core\Config\ConfigFactoryInterface;

interface EmailInterface
{
    /**
     * Injects dependencies into the Email object before rendering.
     *
     * This method is called automatically by the EmailService immediately before dispatching the email, allowing the
     * POPO to maintain a strict, data-only constructor while still accessing Drupal services at runtime.
     *
     * @param ConfigFactoryInterface $configFactory The configuration factory service.
     */
    public function inject(
        ConfigFactoryInterface $configFactory,
    ): void;

    /**
     * Gets the unique identifier for this email template.
     *
     * This key serves as both the theme registry identifier (hook_theme) and the mail manager identifier (hook_mail).
     *
     * @return string The unique key.
     */
    public static function getKey(): string;

    /**
     * Gets the machine name of the module that owns this email.
     *
     * This determines which module's hook_mail() implementation is invoked by the MailManager during dispatch.
     *
     * @return string The module machine name.
     */
    public static function getModuleName(): string;

    /**
     * Gets the sender's email address.
     *
     * @return string|null The 'From' email address, or null to fall back to the site default.
     */
    public function getFromEmail(): ?string;

    /**
     * Gets the sender's display name.
     *
     * @return string|null The 'From' name, or null to fall back to the site default.
     */
    public function getFromName(): ?string;

    /**
     * Gets the translated subject line of the email.
     *
     * @return string The email subject.
     */
    public function getSubject(): string;

    /**
     * Returns the variables required by the Twig template.
     *
     * By utilizing the null coalescing operator (??), this method gracefully returns empty strings when called on an
     * uninitialized instance during hook_theme registration, while returning actual data at runtime.
     *
     * @return array An associative array of template variables.
     */
    public function getVariables(): array;
}