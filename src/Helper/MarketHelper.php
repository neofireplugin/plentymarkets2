<?php
namespace ElasticExportlenandoDE\Helper;
use ElasticExport\Helper\ElasticExportCoreHelper;
use Plenty\Plugin\Log\Loggable;
/**
 * Class MarketHelper
 * @package ElasticExportlenandoDE\Helper
 */
class MarketHelper
{
    use Loggable;
    /**
     * @var ElasticExportCoreHelper
     */
    private $elasticExportHelper;
    /**
     * @var array
     */
    private $configCache = [];
    /**
     * MarketHelper constructor.
     * @param ElasticExportCoreHelper $elasticExportHelper
     */
    public function __construct(ElasticExportCoreHelper $elasticExportHelper)
    {
        $this->elasticExportHelper = $elasticExportHelper;
    }
    /**
     * Get the config for the market.
     *
     * @param string $market
     * @return array
     */
    public function getConfig($market = 'market.kauflux'):array
    {
        if(is_array($this->configCache) && empty($this->configCache))
        {
            $this->configCache = $this->elasticExportHelper->getConfig($market);
        }
        return $this->configCache;
    }
    /**
     * Get the value of a specific key from the configuration.
     *
     * @param  string $key
     * @return string
     */
    public function getConfigValue(string $key):string
    {
        $config = $this->getConfig();
        if(is_array($config) && array_key_exists($key, $config))
        {
            return (string) $config[$key];
        }
        return '';
    }
}
