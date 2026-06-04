<?php
namespace Core\Config;

class CountryRegistry
{
    private static $instance = null;
    private $registry = null;
    private $currentCountry = null;
    
    private function __construct()
    {
        $registryFile = dirname(__DIR__, 3) . '/src/Core/Config/countries_registry.json';
        if (file_exists($registryFile)) {
            $this->registry = json_decode(file_get_contents($registryFile), true);
        } else {
            $this->registry = ['countries' => []];
        }
    }
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getAvailableCountries()
    {
        $countries = [];
        foreach ($this->registry['countries'] as $name => $config) {
            if ($config['enabled'] ?? true) {
                $countries[] = [
                    'name' => $name,
                    'code' => $config['code'],
                    'currency' => $config['currency']
                ];
            }
        }
        return $countries;
    }
    
    public function getCountry($identifier)
    {
        foreach ($this->registry['countries'] as $name => $config) {
            if (strtolower($name) === strtolower($identifier) || 
                strtolower($config['code']) === strtolower($identifier)) {
                return ['name' => $name, 'config' => $config];
            }
        }
        
        $default = $this->registry['default_country'] ?? 'Botswana';
        return [
            'name' => $default,
            'config' => $this->registry['countries'][$default] ?? null
        ];
    }
    
    public function loadCountryConfig($countryName)
    {
        $country = $this->getCountry($countryName);
        if (!$country['config']) {
            return null;
        }
        
        $basePath = ROOT_PATH . '/' . $country['config']['config_path'];
        $participantsFile = $basePath . $country['config']['participants_file'];
        
        if (!file_exists($participantsFile)) {
            return null;
        }
        
        $participantsData = json_decode(file_get_contents($participantsFile), true);
        
        return [
            'country' => $country['name'],
            'code' => $country['config']['code'],
            'currency' => $country['config']['currency'],
            'participants' => $participantsData['participants'] ?? $participantsData ?? [],
            'settings' => file_exists($basePath . $country['config']['config_file']) ? 
                require $basePath . $country['config']['config_file'] : [],
            'fees' => file_exists($basePath . $country['config']['fees_file']) ? 
                json_decode(file_get_contents($basePath . $country['config']['fees_file']), true) : []
        ];
    }
    
    public function getParticipant($countryName, $institutionCode)
    {
        $config = $this->loadCountryConfig($countryName);
        if (!$config) return null;
        
        foreach ($config['participants'] as $code => $participant) {
            if (strtoupper($code) === strtoupper($institutionCode)) {
                return $participant;
            }
        }
        return null;
    }
}
