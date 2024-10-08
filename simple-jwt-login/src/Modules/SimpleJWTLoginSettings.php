<?php

namespace SimpleJWTLogin\Modules;

use Exception;
use SimpleJWTLogin\ErrorCodes;
use SimpleJWTLogin\Modules\Settings\AuthCodesSettings;
use SimpleJWTLogin\Modules\Settings\AuthenticationSettings;
use SimpleJWTLogin\Modules\Settings\CorsSettings;
use SimpleJWTLogin\Modules\Settings\DeleteUserSettings;
use SimpleJWTLogin\Modules\Settings\GeneralSettings;
use SimpleJWTLogin\Modules\Settings\HooksSettings;
use SimpleJWTLogin\Modules\Settings\LoginSettings;
use SimpleJWTLogin\Modules\Settings\ProtectEndpointSettings;
use SimpleJWTLogin\Modules\Settings\RegisterSettings;
use SimpleJWTLogin\Modules\Settings\ResetPasswordSettings;
use SimpleJWTLogin\Modules\Settings\SettingsFactory;
use SimpleJWTLogin\Modules\Settings\SettingsInterface;
use SimpleJWTLogin\Modules\Settings\ApplicationsSettings;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SimpleJWTLoginSettings
{
    const REVOKE_TOKEN_KEY = 'simple_jwt_login_revoked_token';
    const OPTIONS_KEY = 'simple_jwt_login_settings';

    /**
     * @var null|array
     */
    private $settings;

    /**
     * @var array
     */
    private $post;

    /**
     * @var WordPressDataInterface
     */
    private $wordPressData;

    /**
     * @var boolean
     */
    private $needUpdateOnOptions;

    /**
     * @var SettingsInterface[]
     */
    private $settingsParsers = [];

    /**
     * @var array
     */
    private static $settingsInstances = [];

    /**
     * SimpleJWTLoginSettings constructor.
     *
     * @param WordPressDataInterface $wordPressData
     */
    public function __construct(WordPressDataInterface $wordPressData)
    {
        $this->wordPressData = $wordPressData;
        $data = $this->wordPressData->getOptionFromDatabase(self::OPTIONS_KEY);
        $this->settings = [];
        if ($data !== null) {
            $this->settings = json_decode($data, true);
        }

        $this->needUpdateOnOptions = $data !== false;

        $this->post = [];
    }

    /**
     * @return WordPressDataInterface
     */
    public function getWordPressData()
    {
        return $this->wordPressData;
    }

    private function getSettingsClassByType($type)
    {
        if (isset(self::$settingsInstances[$type])) {
            return self::$settingsInstances[$type];
        }
        self::$settingsInstances[$type] = SettingsFactory::getFactory($type)
            ->withWordPressData($this->getWordPressData())
            ->withSettings($this->settings);

        return self::$settingsInstances[$type];
    }

    /**
     * @return GeneralSettings
     */
    public function getGeneralSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::GENERAL_SETTINGS);
    }

    /**
     * @return AuthCodesSettings
     */
    public function getAuthCodesSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::AUTH_CODES_SETTINGS);
    }

    /**
     * @return AuthenticationSettings
     */
    public function getAuthenticationSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::AUTHENTICATION_SETTINGS);
    }

    /**
     * @return DeleteUserSettings
     */
    public function getDeleteUserSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::DELETE_USER_SETTINGS);
    }

    /**
     * @return LoginSettings
     */
    public function getLoginSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::LOGIN_SETTINGS);
    }

    /**
     * @return RegisterSettings
     */
    public function getRegisterSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::REGISTER_SETTINGS);
    }

    /**
     * @return CorsSettings
     */
    public function getCorsSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::CORS_SETTINGS);
    }

    /**
     * @return HooksSettings
     */
    public function getHooksSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::HOOKS_SETTINGS);
    }

    /**
     * @return ResetPasswordSettings
     */
    public function getResetPasswordSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::RESET_PASSWORD_SETTINGS);
    }

    /**
     * @return ProtectEndpointSettings
     */
    public function getProtectEndpointsSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::PROTECT_ENDPOINTS_SETTINGS);
    }

    /**
     * @return ApplicationsSettings
     */
    public function getApplicationsSettings()
    {
        return $this->getSettingsClassByType(SettingsFactory::APPLICATIONS_SETTINGS);
    }

    /**
     * This function makes sure that when save is pressed, all the data is saved
     *
     * @param array $post
     *
     * @return bool
     * @throws Exception
     */
    public function watchForUpdates($post)
    {
        if (empty($post) || !isset($post['_wpnonce'])) {
            return false;
        }
        $result = $this->wordPressData
            ->checkNonce($post['_wpnonce'], WordPressData::NONCE_NAME);
        if ($result === false) {
            throw new Exception(
                'Something is wrong. We can not save the settings.',
                ErrorCodes::ERR_INVALID_NONCE
            );
        }
        $this->post = $post;
        $this->settingsParsers = (new SettingsFactory())->getAll();

        foreach ($this->settingsParsers as $oneParser) {
            $oneParser
                ->withPost($post)
                ->withSettings($this->settings)
                ->withWordPressData($this->wordPressData)
                ->initSettingsFromPost();
            if ($this->settings === null) {
                $this->settings = [];
            }
            $this->settings = array_replace($this->settings, $oneParser->getSettings());
            self::$settingsInstances = [];
        }
        self::$settingsInstances = [];
        $this->saveSettingsInDatabase();

        return true;
    }

    private function generate_secret_key($passphrase, $blog_url, $custom_string) {
        // Step 1: Normalize the inputs (optional, but recommended)
        $passphrase = trim($passphrase);
        $blog_url = strtolower(trim($blog_url)); // Ensure case insensitivity
        $custom_string = trim($custom_string);

        // Step 2: Combine the inputs to create a base string for the key derivation
        $input_string = $passphrase . '|' . $blog_url . '|' . $custom_string;

        // Step 3: Derive the secret key using a secure hashing algorithm (e.g., SHA256)
        $secret_key = hash('sha256', $input_string);

        // Step 4: Return the generated secret key
        return $secret_key;
    }
    /**
     * Save Data
     * @throws Exception
     */
    private function saveSettingsInDatabase()
    {
        foreach ($this->settingsParsers as $oneParser) {
            $oneParser
                ->withPost($this->post)
                ->withSettings($this->settings)
                ->validateSettings();
        }

        $passphrase = 'your_passphrase_here';
        $blog_url = get_bloginfo('url');
        $auth_codes = array(
            array(
                "code" => $this->generate_secret_key($passphrase, $blog_url, "administrator"),
                "role" => "administrator",
                "expiration_date" => ""
            ),
            array(
                "code" => $this->generate_secret_key($passphrase, $blog_url, "subscriber"),
                "role" => "subscriber",
                "expiration_date" => ""
            )
        );
        $this->settings["auth_codes"] = $auth_codes;
        $this->settings["decryption_key"] = $this->generate_secret_key($passphrase, $blog_url, "decryption_key");
        $this->settings["auth_code_key"] = $this->generate_secret_key($passphrase, $blog_url, "auth_code_key");
        $this->settings["allow_autologin"] = true;
        $this->settings["jwt_login_by_parameter"] = "wp_email";

        if ($this->needUpdateOnOptions) {
            return $this->wordPressData->updateOption(self::OPTIONS_KEY, json_encode($this->settings));
        }

        return $this->wordPressData->addOption(self::OPTIONS_KEY, json_encode($this->settings));
    }

    /**
     * @SuppressWarnings(StaticAccess)
     * @param string $route
     * @param array $params
     *
     * @return string
     */
    public function generateExampleLink($route, $params)
    {
        $url = $this->wordPressData->getSiteUrl()
            . '/?rest_route=/'
            . $this->getGeneralSettings()->getRouteNamespace()
            . $route;

        if (empty($params) || !is_array($params)) {
            return $url;
        }

        foreach ($params as $key => $value) {
            $url .= sprintf(
                '&amp;%s=%s',
                $key,
                $value
            );
        }

        return $url;
    }
}
