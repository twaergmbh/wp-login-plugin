<?php
namespace SimpleJWTLogin\Modules;

use Exception;
use SimpleJWTLogin\Modules\Settings\AuthCodesSettings;
use SimpleJWTLogin\Modules\Settings\AuthenticationSettings;
use SimpleJWTLogin\Modules\Settings\CorsSettings;
use SimpleJWTLogin\Modules\Settings\DeleteUserSettings;
use SimpleJWTLogin\Modules\Settings\GeneralSettings;
use SimpleJWTLogin\Modules\Settings\HooksSettings;
use SimpleJWTLogin\Modules\Settings\LoginSettings;
use SimpleJWTLogin\Modules\Settings\RegisterSettings;
use SimpleJWTLogin\Modules\Settings\SettingsFactory;
use SimpleJWTLogin\Modules\Settings\SettingsInterface;

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
        $this->settings = json_decode($data, true);
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
     * This function makes sure that when save is pressed, all the data is saved
     *
     * @param array $post
     *
     * @return bool|void
     * @throws Exception
     */
    public function watchForUpdates($post)
    {
        if (empty($post)) {
            return false;
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

        if ($this->needUpdateOnOptions) {
            return $this->wordPressData->updateOption(self::OPTIONS_KEY, json_encode($this->settings));
        }

        return $this->wordPressData->addOption(self::OPTIONS_KEY, json_encode($this->settings));
    }

    /**
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
        foreach ($params as $key => $value) {
            $url .= sprintf('&amp;%s=<b>%s</b>', $key, $value);
        }

        return $url;
    }
}
