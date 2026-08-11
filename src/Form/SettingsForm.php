<?php /** @noinspection PhpUnused */

namespace Drupal\omnia\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\esn_accounts_api\Entity\Organisation;
use Drupal\omnia\Config\OmniaSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SettingsForm extends ConfigFormBase
{
    protected EntityTypeManagerInterface $entityTypeManager;

    public function __construct(
        ConfigFactoryInterface     $configFactory,
        EntityTypeManagerInterface $entityTypeManager,
    )
    {
        parent::__construct($configFactory);
        $this->entityTypeManager = $entityTypeManager;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        return new static(
            $configFactory,
            $entityTypeManager,
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function getEditableConfigNames(): array
    {
        return [OmniaSettings::CONFIG_NAME];
    }

    /**
     * {@inheritDoc}
     */
    public function getFormId(): string
    {
        return 'omnia_settings';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $omniaSettings = new OmniaSettings($this->configFactory);

        $form['#prefix'] = '<div id="omnia-settings-wrapper">';
        $form['#suffix'] = '</div>';

        $form['switches'] = [
            '#type' => 'details',
            '#title' => $this->t('Enable / Disable Integrations'),
            '#open' => TRUE
        ];

        $form['switches']['switch_stripe'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Stripe Integration'),
            '#default_value' => $form_state->getValue('switch_stripe') ?? $omniaSettings->getStripeSwitch(),
            '#ajax' => [
                'callback' => '::switchToggle',
                'wrapper' => 'omnia-settings-wrapper'
            ]
        ];

        $form['switches']['switch_google'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Google Integration'),
            '#default_value' => $form_state->getValue('switch_google') ?? $omniaSettings->getGoogleSwitch(),
            '#ajax' => [
                'callback' => '::switchToggle',
                'wrapper' => 'omnia-settings-wrapper'
            ]
        ];

        $form['switches']['switch_apple'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Apple Integration'),
            '#default_value' => $form_state->getValue('switch_apple') ?? $omniaSettings->getAppleSwitch(),
            '#ajax' => [
                'callback' => '::switchToggle',
                'wrapper' => 'omnia-settings-wrapper'
            ]
        ];

        $form['organisation'] =[
            '#type' => 'details',
            '#title' => $this->t('Organisation Settings'),
            '#description' => $this->t('Configuration for the ESN Organisation.'),
            '#open' => true
        ];

        try {
            /** @var Organisation[] $nationalOrganisations */
            $nationalOrganisations = $this->entityTypeManager->getStorage('esn_organisation')->loadByProperties(['type' => 'country']);
        } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
            $form['organisation']['organisation_error'] = [
                '#type' => 'markup',
                '#markup' => '<div class="alert alert-warning">' . $this->t('Could not fetch the NOs from the ESN Accounts API. Please ensure that its works properly and try again.') . '</div>',
            ];
            return $form;
        }

        $noNames = [];
        foreach ($nationalOrganisations as $no) {
            $noNames[$no->id()] = $no->getTitle();
        }
        asort($noNames);

        $form['organisation']['national_organisation_id'] = [
            '#type' => 'select',
            '#title' => $this->t('National Organisation Name'),
            '#description' => $this->t('Select the name of your National Organisation.'),
            '#options' => $noNames ?? [],
            '#empty_option' => $this->t('- Select -'),
            '#default_value' => $omniaSettings->getNationalOrganisationID(),
            '#required' => true,
            '#ajax' => [
                'callback' => '::toggleSectionMode',
                'wrapper' => 'section-mode',
            ],
        ];

        $form['organisation']['section_mode'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Section Mode'),
            '#default_value' => $omniaSettings->getSectionMode(),
            '#ajax' => [
                'callback' => '::toggleSectionMode',
                'wrapper' => 'section-mode',
            ]
        ];

        $selectedNO = $form_state->getValue('national_organisation_id') ?: $omniaSettings->getNationalOrganisationID();
        $sectionModeEnabled = $form_state->getValue('section_mode') ?? $omniaSettings->getSectionMode();

        if ($sectionModeEnabled && !empty($selectedNO)) {
            /** @var Organisation $nationalOrganisation */
            /** @noinspection PhpUnhandledExceptionInspection */
            $nationalOrganisation = $this->entityTypeManager->getStorage('esn_organisation')->load($selectedNO);

            if (!empty($nationalOrganisations)) {
                /** @var Organisation[] $sections */
                /** @noinspection PhpUnhandledExceptionInspection */
                $sections = $this->entityTypeManager->getStorage('esn_organisation')->loadByProperties(['type' => 'section', 'country_code' => $nationalOrganisation->getCountryCode()]);

                foreach ($sections as $section) {
                    $sectionNames[$section->id()] = $section->getTitle();
                }
                asort($sectionNames);
            }
        }

        $form['organisation']['section_wrapper'] = [
            '#type' => 'container',
            '#attributes' => ['id' => 'section-mode-wrapper'],
        ];

        if ($sectionModeEnabled) {
            $form['organisation']['section_wrapper']['section_id'] = [
                '#type' => 'select',
                '#title' => $this->t('Section Name'),
                '#description' => $this->t('Select the name of your Section.'),
                '#options' => $sectionNames ?? [],
                '#empty_option' => $this->t('- Select -'),
                '#default_value' => $omniaSettings->getOrganisationID(),
                '#required' => true,
                '#validated' => true
            ];
        }

        $form['email'] = [
            '#type' => 'details',
            '#title' => $this->t('Email Settings'),
            '#description' => $this->t('Configuration for the parameters needed for sending emails.'),
            '#open' => true
        ];

        $form['email']['email_address'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Sender Email Address'),
            '#description' => $this->t('Enter the email address from where the emails will be sent.'),
            '#default_value' => $omniaSettings->getEmailAddress(),
            '#required' => true
        ];

        $form['email']['email_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Sender Email Name'),
            '#description' => $this->t('Enter the user-friendly name from where the emails will be sent.'),
            '#default_value' => $omniaSettings->getEmailName(),
            '#required' => true
        ];

        $form['email']['email_footer'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Email Footer'),
            '#description' => $this->t('Enter the HTML for the footer of the emails to be sent.'),
            '#default_value' => $omniaSettings->getEmailFooter(),
            '#required' => true
        ];

        $form['stripe'] = [
            '#type' => 'details',
            '#title' => $this->t('Stripe Settings'),
            '#description' => $this->t('Configuration for the Stripe Integration.'),
            '#open' => $form_state->getValue('switch_stripe') ?? $omniaSettings->getStripeSwitch()
        ];

        $form['stripe']['stripe_secret_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Secret Key'),
            '#description' => $this->t('Enter the Stripe Secret Key.'),
            '#default_value' => $form_state->getValue('stripe_secret_key') ?? $omniaSettings->getStripeSecretKey(),
            '#required' => $form_state->getValue('switch_stripe') ?? $omniaSettings->getStripeSwitch()
        ];

        $form['google'] = [
            '#type' => 'details',
            '#title' => $this->t('Google Settings'),
            '#description' => $this->t('Configuration for the Google Integration.'),
            '#open' => $form_state->getValue('switch_google') ?? $omniaSettings->getGoogleSwitch()
        ];

        if ($email = $omniaSettings->getGoogleClientEmail()) {
            $form['google']['current_status'] = [
                '#markup' => '<div class="alert alert-success">' .
                    $this->t('Currently connected as: <strong>@email</strong>', ['@email' => $email]) .
                    '</div>',
            ];
        } else {
            $form['google']['current_status'] = [
                '#markup' => '<div class="alert alert-warning">' .
                    $this->t('No Service Account credentials configured.') .
                    '</div>',
            ];
        }

        $form['google']['google_json_key_file'] = [
            '#type' => 'file',
            '#title' => $this->t('Upload Google Service Account JSON'),
            '#description' => $this->t('Upload the .json file you downloaded from Google Console. The system will extract the keys and discard the file.'),
            '#attributes' => [
                'accept' => '.json',
            ],
            '#disabled' => !($form_state->getValue('switch_google') ?? $omniaSettings->getGoogleSwitch()),
            '#required' => empty($email) && ($form_state->getValue('switch_google') ?? $omniaSettings->getGoogleSwitch())
        ];

        $form['google']['google_issuer_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Issuer ID'),
            '#description' => $this->t('The Issuer ID from the Google Wallet Console.'),
            '#default_value' => $omniaSettings->getGoogleIssuerID(),
            '#disabled' => !($form_state->getValue('switch_google') ?? $omniaSettings->getGoogleSwitch()),
        ];

        $form['apple'] = [
            '#type' => 'details',
            '#title' => $this->t('Apple Wallet Settings'),
            '#description' => $this->t('Configuration for the Apple Wallet Service.'),
            '#open' => $form_state->getValue('switch_apple') ?? $omniaSettings->getAppleSwitch()
        ];

        $form['apple']['apple_team_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Apple Team ID'),
            '#description' => $this->t('Your Apple Team ID.'),
            '#default_value' => $omniaSettings->getAppleTeamID(),
            '#disabled' => !($form_state->getValue('switch_apple') ?? $omniaSettings->getAppleSwitch()),
            '#required' => $form_state->getValue('switch_apple') ?? $omniaSettings->getAppleSwitch()
        ];

        return parent::buildForm($form, $form_state);
    }

    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        parent::validateForm($form, $form_state);

        $allFiles = $this->getRequest()->files->get('files', []);

        /** @var UploadedFile $googleFile */
        $googleFile = $allFiles['google_json_key_file'] ?? NULL;
        if ($googleFile instanceof UploadedFile) {
            if ($googleFile->isValid()) {
                $content = file_get_contents($googleFile->getRealPath());
                $json = json_decode($content, TRUE);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $form_state->setErrorByName('google_json_key_file', $this->t('The uploaded file is not valid JSON.'));
                    return;
                }

                if (empty($json['client_email']) || empty($json['private_key'])) {
                    $form_state->setErrorByName('google_json_key_file', $this->t('The JSON file does not contain "client_email" or "private_key". Are you sure this is a Service Account key file?'));
                    return;
                }

                $form_state->set('parsed_google_credentials', $json);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $omniaSettings = new OmniaSettings($this->configFactory, true);

        if ($form_state->getValue('section_mode')) {
            $sectionMode = true;
            $sectionID = $form_state->getValue('section_id');
            /** @var Organisation $selectedOrganisation */
            /** @noinspection PhpUnhandledExceptionInspection */
            $selectedOrganisation = $this->entityTypeManager->getStorage('esn_organisation')->load($sectionID);
        } else {
            $sectionMode = false;
            $nationalOrganisationID = $form_state->getValue('national_organisation_id');
            /** @var Organisation $selectedOrganisation */
            /** @noinspection PhpUnhandledExceptionInspection */
            $selectedOrganisation = $this->entityTypeManager->getStorage('esn_organisation')->load($nationalOrganisationID);
        }

        $omniaSettings
            ->setStripeSwitch($form_state->getValue('switch_stripe'))
            ->setGoogleSwitch($form_state->getValue('switch_google'))
            ->setAppleSwitch($form_state->getValue('switch_apple'))
            ->setNationalOrganisationID($form_state->getValue('national_organisation_id'))
            ->setOrganisationID($selectedOrganisation->id())
            ->setOrganisationName($selectedOrganisation->getTitle())
            ->setOrganisationLogoURL($selectedOrganisation->getRemoteLogoPath())
            ->setSectionMode($sectionMode)
            ->setEmailAddress($form_state->getValue('email_address'))
            ->setEmailName($form_state->getValue('email_name'))
            ->setEmailFooter($form_state->getValue('email_footer'))
            ->setStripeSecretKey($form_state->getValue('stripe_secret_key'))
            ->setGoogleIssuerID($form_state->getValue('google_issuer_id'))
            ->setAppleTeamID($form_state->getValue('apple_team_id'));

        if ($googleCredentials = $form_state->get('parsed_google_credentials')) {
            $omniaSettings
                ->setGoogleClientEmail($googleCredentials['client_email'])
                ->setGooglePrivateKey($googleCredentials['private_key'])
                ->setGooglePrivateKeyID($googleCredentials['client_id'] ?? '')
                ->setGoogleProjectID($googleCredentials['project_id'] ?? '')
                ->setGoogleClientID($googleCredentials['client_id'] ?? '');

            $this->messenger()->addStatus($this->t('Credentials updated for @email. Remember to share permissions with this email!', ['@email' => $googleCredentials['client_email']]));
        }

        $omniaSettings->save();

        parent::submitForm($form, $form_state);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function switchToggle(array $form, FormStateInterface $form_state): array
    {
        return $form;
    }

    public function toggleSectionMode(array $form, FormStateInterface $form_state): AjaxResponse
    {
        $response = new AjaxResponse();

        if (empty($form_state->getValue('national_organisation_id')) && $form_state->getValue('section_mode')) {
            $this->messenger()->addError($this->t('Please select your National Organisation before you enable Section Mode.'));

            $response->addCommand(new PrependCommand('#section-mode-wrapper', ['#type' => 'status_messages']));
            $response->addCommand(new ReplaceCommand('#section-mode-wrapper', $form['organisation']['section_wrapper']));
            return $response;
        }

        $response->addCommand(new ReplaceCommand('#section-mode-wrapper', $form['organisation']['section_wrapper']));
        return $response;
    }
}