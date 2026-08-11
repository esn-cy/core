<?php /** @noinspection PhpUnused */

namespace Drupal\omnia\Mail;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\omnia\Config\OmniaSettings;

abstract class EmailBase implements EmailInterface
{
    protected ConfigFactoryInterface $configFactory;

    /**
     * {@inheritDoc}
     */
    public function inject(
        ConfigFactoryInterface $configFactory
    ): void
    {
        $this->configFactory = $configFactory;
    }

    /**
     * {@inheritDoc}
     */
    public static function getModuleName(): string
    {
        return 'omnia';
    }

    /**
     * {@inheritDoc}
     */
    public function getFromEmail(): ?string
    {
        $omniaSettings = new OmniaSettings($this->configFactory);
        return $omniaSettings->getEmailAddress();
    }

    /**
     * {@inheritDoc}
     */
    public function getFromName(): ?string
    {
        $omniaSettings = new OmniaSettings($this->configFactory);
        return $omniaSettings->getEmailName();
    }

    /**
     * {@inheritDoc}
     */
    public function getVariables(): array
    {
        $settings = isset($this->configFactory) ? new OmniaSettings($this->configFactory) : null;

        return [
            'logo_location' => $settings ? $settings->getOrganisationLogoURL() : '',
            'organisation_name' => $settings ? $settings->getOrganisationName() : '',
            'footer' => $settings ? $settings->getEmailFooter() : '',
        ];
    }
}