<?php /** @noinspection PhpUnused */

namespace Drupal\omnia\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\omnia\Config\OmniaSettings;
use Exception;
use Firebase\JWT\JWT;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Walletobjects;
use Google\Service\Walletobjects\GenericClass;
use Google\Service\Walletobjects\GenericObject;
use Google\Service\Walletobjects\LocalizedString;
use Google\Service\Walletobjects\TranslatedString;
use GuzzleHttp\Exception\GuzzleException;

class GoogleServiceBase
{
    protected ConfigFactoryInterface $configFactory;
    protected FileServiceBase $fileService;
    protected LoggerChannelInterface $logger;
    protected ?GoogleClient $client = NULL;
    protected ?Walletobjects $walletService = NULL;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        FileServiceBase               $fileService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->configFactory = $configFactory;
        $this->fileService = $fileService;
        $this->logger = $loggerFactory->get('omnia');
    }


    /**
     * Gets the initialized Google client.
     *
     * @return ?GoogleClient The Google client instance, or null if the configuration is missing or invalid.
     */
    protected function getClient(): ?GoogleClient
    {
        if ($this->client) {
            return $this->client;
        }

        $omniaSettings = new OmniaSettings($this->configFactory);

        $clientEmail = $omniaSettings->getGoogleClientEmail();
        $privateKey = $omniaSettings->getGooglePrivateKey();

        if (empty($clientEmail) || empty($privateKey)) {
            $this->logger->error('Google Service Account credentials were not configured.');
            return null;
        }

        $privateKey = str_replace("\\n", "\n", $privateKey);

        $authConfig = [
            'type' => 'service_account',
            'project_id' => $omniaSettings->getGoogleProjectID(),
            'private_key_id' => $omniaSettings->getGooglePrivateKeyID(),
            'private_key' => $privateKey,
            'client_email' => $clientEmail,
            'client_id' => $omniaSettings->getGoogleClientID(),
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/' . urlencode($clientEmail),
        ];

        try {
            $client = new GoogleClient();
            $client->setApplicationName('Omnia');
            $client->setScopes([Sheets::SPREADSHEETS, Walletobjects::WALLET_OBJECT_ISSUER]);
            $client->setAuthConfig($authConfig);
            $client->setAccessType('offline');
            $this->client = $client;

            $this->walletService = new Walletobjects($this->client);

            return $client;
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize Google Client: @message', ['@message' => $e->getMessage()]);
            return NULL;
        }
    }

    /**
     * Gets a Google Wallet generic class by its class name.
     *
     * @param string $className The name of the class to retrieve.
     *
     * @return string|null The class ID if found, or null if not found.
     *
     * @throws Exception
     */
    protected function getClass(string $className): ?string
    {
        $client = $this->getClient();
        if (!$client) {
            throw new Exception('Google Service Account credentials were not configured.');
        }

        $omniaSettings = new OmniaSettings($this->configFactory);
        $issuerID = $omniaSettings->getGoogleIssuerID();

        $classID = "$issuerID.$className";

        try {
            $this->walletService->genericclass->get($classID);
            return $classID;
        } catch (\Google\Service\Exception $error) {
            if (empty($error->getErrors()) || $error->getErrors()[0]['reason'] != 'classNotFound') {
                throw $error;
            }
        }
        return null;
    }

    /**
     * Creates a new Google Wallet generic class.
     *
     * @param GenericClass $class The generic class object to create.
     *
     * @return string|null The ID of the created class, or null on failure.
     *
     * @throws \Google\Service\Exception
     */
    protected function createClass(GenericClass $class): ?string
    {
        $response = $this->walletService->genericclass->insert($class);
        return $response->id;
    }

    /**
     * Generates a save-to-wallet link for a given object ID.
     *
     * @param string $objectID The ID of the generic object.
     *
     * @return string The generated JWT link to save the object to Google Wallet.
     *
     * @throws Exception
     */
    private function getLink(string $objectID): string
    {
        if (!$this->getClient()) {
            throw new Exception('Google Service Account credentials were not configured.');
        }

        $omniaSettings = new OmniaSettings($this->configFactory);

        $clientEmail = $omniaSettings->getGoogleClientEmail();
        $privateKey = $omniaSettings->getGooglePrivateKey();

        $claims = [
            'iss' => $clientEmail,
            'aud' => 'google',
            'origins' => ['esncy.org'],
            'typ' => 'savetowallet',
            'payload' => [
                'genericObjects' => [
                    ['id' => $objectID]
                ]
            ]
        ];

        $token = JWT::encode(
            $claims,
            $privateKey,
            'RS256'
        );

        return "https://pay.google.com/gp/v/save/$token";
    }

    /**
     * Gets a Google Wallet generic object by its object name.
     *
     * @param string $objectName The name of the object to retrieve.
     *
     * @return string|null The save-to-wallet link if the object is found, or null if not found.
     *
     * @throws \Google\Service\Exception
     * @throws Exception
     * @throws GuzzleException
     */
    protected function getObject(string $objectName): ?string
    {
        $client = $this->getClient();
        if (!$client) {
            throw new Exception('Google Service Account credentials were not configured.');
        }

        $omniaSettings = new OmniaSettings($this->configFactory);

        $issuerID = $omniaSettings->getGoogleIssuerID();

        $objectID = "$issuerID.$objectName";
        try {
            $this->walletService->genericobject->get($objectID);
            return $this->getLink($objectID);
        } catch (\Google\Service\Exception $error) {
            if (empty($error->getErrors()) || $error->getErrors()[0]['reason'] != 'resourceNotFound') {
                throw $error;
            }
            return null;
        }
    }


    /**
     * Creates a new Google Wallet generic object.
     *
     * @param GenericObject $object The generic object to create.
     *
     * @return string|null The save-to-wallet link for the created object.
     *
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    protected function createObject(GenericObject $object): ?string
    {
        try {
            $inserted = $this->walletService->genericobject->insert($object);
            return $this->getLink($inserted->id);
        } catch (\Google\Service\Exception $error) {
            if (!empty($error->getErrors()) && $error->getErrors()[0]['reason'] === 'existingResource') {
                return $this->getLink($object->id);
            }
            throw $error;
        }
    }

    /**
     * Updates an existing Google Wallet generic object.
     *
     * @param GenericObject $object The generic object with updated data.
     *
     * @return bool True if the object was updated successfully, false otherwise.
     *
     * @throws GuzzleException
     * @throws Exception
     */
    protected function updateObject(GenericObject $object): bool
    {
        $client = $this->getClient();
        if (!$client) {
            throw new Exception('Google Service Account credentials were not configured.');
        }

        try {
            @$this->walletService->genericobject->get($object->id);
        } catch (\Google\Service\Exception) {
            return true;
        }

        try {
            $this->walletService->genericobject->update($object->id, $object);
        } catch (\Google\Service\Exception) {
            return false;
        }
        return true;
    }

    /**
     * Deletes (expires) a Google Wallet generic object.
     *
     * @param string $objectID The ID of the object to delete.
     *
     * @return bool True if the object was deleted successfully, false otherwise.
     *
     * @throws Exception
     */
    protected function deleteObject(string $objectID): bool
    {
        $client = $this->getClient();
        if (!$client) {
            throw new Exception('Google Service Account credentials were not configured.');
        }

        try {
            @$this->walletService->genericobject->get($objectID);
        } catch (\Google\Service\Exception) {
            return true;
        }

        $patchObject = new GenericObject([]);
        $patchObject->setState('EXPIRED');
        $patchObject->setTextModulesData([]);
        $patchObject->setImageModulesData([]);
        $patchObject->setHeader(new LocalizedString([
            'defaultValue' => new TranslatedString([
                'language' => 'en-US',
                'value' => ''
            ])
        ]));

        try {
            $this->walletService->genericobject->patch($objectID, $patchObject);
        } catch (\Google\Service\Exception) {
            return false;
        }
        return true;
    }

    /**
     * Uploads a private image to Google Wallet.
     *
     * @param string $fileID The ID of the file entity representing the image.
     *
     * @return string The uploaded private image ID.
     *
     * @throws Exception
     * @throws GuzzleException
     */
    protected function uploadPrivateImage(string $fileID): string
    {
        $mimeType = $this->fileService->getFileMimeType($fileID);
        $imageContents = $this->fileService->readFile($fileID);
        if (empty($mimeType) || empty($imageContents)) {
            throw new Exception("Unable to read the image file.");
        }

        $client = $this->getClient();
        if (!$client) {
            throw new Exception('Google Service Account credentials were not configured.');
        }

        $omniaSettings = new OmniaSettings($this->configFactory);

        if (!($issuerID = $omniaSettings->getGoogleIssuerID())) {
            throw new Exception('Google Wallet Issuer ID is missing from settings.');
        }

        $httpClient = $client->authorize();
        $response = $httpClient->request('POST', "https://walletobjects.googleapis.com/upload/walletobjects/v1/privateContent/$issuerID/uploadPrivateImage", [
            'headers' => [
                'Content-Type' => $mimeType,
            ],
            'body' => $imageContents,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new Exception("Failed to upload private image to Google Wallet. HTTP Status: $status");
        }

        $result = json_decode((string)$response->getBody(), TRUE);
        if (empty($result['privateImageId'])) {
            throw new Exception("Could not retrieve privateImageId from response.");
        }

        return $result['privateImageId'];
    }
}