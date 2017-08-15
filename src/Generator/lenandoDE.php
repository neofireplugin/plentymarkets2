<?php
namespace ElasticExportlenandoDE\Generator;
use ElasticExport\Helper\ElasticExportPriceHelper;
use ElasticExport\Helper\ElasticExportStockHelper;
use ElasticExportlenandoDE\Helper\MarketHelper;
use ElasticExportlenandoDE\Helper\PropertyHelper;
use ElasticExportlenandoDE\Helper\StockHelper;
use Plenty\Modules\DataExchange\Contracts\CSVPluginGenerator;
use Plenty\Modules\Helper\Services\ArrayHelper;
use Plenty\Modules\DataExchange\Models\FormatSetting;
use ElasticExport\Helper\ElasticExportCoreHelper;
use Plenty\Modules\Helper\Models\KeyValue;
use Plenty\Modules\Item\ItemCrossSelling\Contracts\ItemCrossSellingRepositoryContract;
use Plenty\Modules\Item\Search\Contracts\VariationElasticSearchScrollRepositoryContract;
// NEU
use Plenty\Modules\Market\Helper\Contracts\MarketPropertyHelperRepositoryContract;
// NEU
use Plenty\Plugin\Log\Loggable;
/**
 * Class lenandoDE
 * @package ElasticExportlenandoDE\Generator
 */
class lenandoDE extends CSVPluginGenerator
{
    use Loggable;
	
	
	const RAKUTEN_DE = 106.00;
    const PROPERTY_TYPE_ENERGY_CLASS       = 'energy_efficiency_class';
    const PROPERTY_TYPE_ENERGY_CLASS_GROUP = 'energy_efficiency_class_group';
    const PROPERTY_TYPE_ENERGY_CLASS_UNTIL = 'energy_efficiency_class_until';
	
	
	const CHARACTER_TYPE_ENERGY_EFFICIENCY_CLASS	= 'energy_efficiency_class';
	
    const LENANDO_DE = 116.00;
    const DELIMITER = ";";
	
    const STATUS_VISIBLE = 1;
    const STATUS_LOCKED = 1;
    const STATUS_HIDDEN = 1;
    /**
     * @var ElasticExportCoreHelper $elasticExportHelper
     */
    private $elasticExportHelper;
    /**
     * @var ElasticExportStockHelper
     */
    private $elasticExportStockHelper;
    /**
     * @var ElasticExportPriceHelper
     */
    private $elasticExportPriceHelper;
	
	// NEU
	/**
     * MarketPropertyHelperRepositoryContract $marketPropertyHelperRepository
     */
    private $marketPropertyHelperRepository;
    
    // NEU
    /**
     * @var ItemCrossSellingRepositoryContract
     */
    private $itemCrossSellingRepository;
    /**
     * @var ArrayHelper
     */
    private $arrayHelper;
    /**
     * @var PropertyHelper
     */
    private $propertyHelper;
    /**
     * @var StockHelper
     */
    private $stockHelper;
    /**
     * @var MarketHelper
     */
    private $marketHelper;
    /**
     * @var array
     */
    private $shippingCostCache;
    /**
     * @var array
     */
    private $manufacturerCache;
    /**
     * @var array
     */
    private $itemCrossSellingListCache;
    /**
     * @var array
     */
    private $addedItems = [];
    /**
     * @var array
     */
    private $flags = [
        0 => '',
        1 => 'Sonderangebot',
        2 => 'Neuheit',
        3 => 'Top Artikel',
    ];
    /**
     * lenandoDE constructor.
     *
     * @param ArrayHelper $arrayHelper
     * @param PropertyHelper $propertyHelper
     */
    public function __construct(
        ArrayHelper $arrayHelper,
        // NEU
        MarketPropertyHelperRepositoryContract $marketPropertyHelperRepository,
        // NEU
        PropertyHelper $propertyHelper,
        StockHelper $stockHelper,
        MarketHelper $marketHelper,
        ItemCrossSellingRepositoryContract $itemCrossSellingRepository
    )
    {
        $this->arrayHelper = $arrayHelper;
        // NEU
        $this->marketPropertyHelperRepository = $marketPropertyHelperRepository;
        // NEU
        $this->propertyHelper = $propertyHelper;
        $this->stockHelper = $stockHelper;
        $this->marketHelper = $marketHelper;
        $this->itemCrossSellingRepository = $itemCrossSellingRepository;
    }
    /**
     * Generates and populates the data into the CSV file.
     *
     * @param VariationElasticSearchScrollRepositoryContract $elasticSearch
     * @param array $formatSettings
     * @param array $filter
     */
    protected function generatePluginContent($elasticSearch, array $formatSettings = [], array $filter = [])
    {
        $this->elasticExportHelper = pluginApp(ElasticExportCoreHelper::class);
        $this->elasticExportStockHelper = pluginApp(ElasticExportStockHelper::class);
        $this->elasticExportPriceHelper = pluginApp(ElasticExportPriceHelper::class);
        $settings = $this->arrayHelper->buildMapFromObjectList($formatSettings, 'key', 'value');
        $this->setDelimiter(self::DELIMITER);
        $this->addCSVContent($this->head());
        $startTime = microtime(true);
        if($elasticSearch instanceof VariationElasticSearchScrollRepositoryContract)
        {
            // Initiate the counter for the variations limit
            $limitReached = false;
            $limit = 0;
            do
            {
                $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.writtenLines', [
                    'Lines written' => $limit,
                ]);
                // Stop writing if limit is reached
                if($limitReached === true)
                {
                    break;
                }
                $esStartTime = microtime(true);
                // Get the data from Elastic Search
                $resultList = $elasticSearch->execute();
                $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.esDuration', [
                    'Elastic Search duration' => microtime(true) - $esStartTime,
                ]);
                if(count($resultList['error']) > 0)
                {
                    $this->getLogger(__METHOD__)->error('ElasticExportlenandoDE::log.occurredElasticSearchErrors', [
                        'Error message' => $resultList['error'],
                    ]);
                }
                $buildRowsStartTime = microtime(true);
                if(is_array($resultList['documents']) && count($resultList['documents']) > 0)
                {
                    $previousItemId = null;
                    foreach ($resultList['documents'] as $variation)
                    {
                        // Stop and set the flag if limit is reached
                        if($limit == $filter['limit'])
                        {
                            $limitReached = true;
                            break;
                        }
                        // If filtered by stock is set and stock is negative, then skip the variation
                        if($this->elasticExportStockHelper->isFilteredByStock($variation, $filter) === true)
                        {
                            $this->getLogger(__METHOD__)->info('ElasticExportlenandoDE::log.variationNotPartOfExportStock', [
                                'VariationId' => (string)$variation['id']
                            ]);
                            continue;
                        }
                        
                        // Skip the variations that do not have attributes, print just the main variation in that case
                        $attributes = $this->getAttributeNameValueCombination($variation, $settings);
                        if(strlen($attributes) <= 0 && $variation['variation']['isMain'] === false)
                        {
                            $this->getLogger(__METHOD__)->info('ElasticExportBilligerDE::log.variationNoAttributesError', [
                                'VariationId' => (string)$variation['id']
                            ]);
                            continue;
                        }
                        // If is not valid, then skip the variation
                        if(!$this->stockHelper->isValid($variation))
                        {
                            continue;
                        }
                        try
                        {
                            // Set the caches if we have the first variation or when we have the first variation of an item
                            if($previousItemId === null || $previousItemId != $variation['data']['item']['id'])
                            {
                                $previousItemId = $variation['data']['item']['id'];
                                unset($this->shippingCostCache, $this->itemCrossSellingListCache);
                                // Build the caches arrays
                                $this->buildCaches($variation, $settings);
                            }
                            // Build the new row for printing in the CSV file
                           
                            $this->buildRow($variation, $settings, $attributes);
                            
                            
                        }
                        catch(\Throwable $throwable)
                        {
                            $this->getLogger(__METHOD__)->error('ElasticExportlenandoDE::logs.fillRowError', [
                                'Error message ' => $throwable->getMessage(),
                                'Error line'     => $throwable->getLine(),
                                'VariationId'    => (string)$variation['id']
                            ]);
                        }
                        // New line was added
                        $limit++;
                    }
                    $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.buildRowsDuration', [
                        'Build rows duration' => microtime(true) - $buildRowsStartTime,
                    ]);
                }
            } while ($elasticSearch->hasNext());
        }
        $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.fileGenerationDuration', [
            'Whole file generation duration' => microtime(true) - $startTime,
        ]);
    }
    /**
     * Creates the header of the CSV file.
     *
     * @return array
     */
    private function head():array
    {
        return array(
            'Produktname',
				'Artikelnummer',
				'ean',
				'Hersteller',
				'Steuersatz',
				'Preis',
				'Kurzbeschreibung',
				'Beschreibung',
				'Versandkosten',
				'Lagerbestand',
				'Kategoriestruktur',
				'Attribute',
				'Gewicht',
				'Lieferzeit',
				'Nachnahmegebühr',
				'MPN',
				'Bildlink',
				'Bildlink2',
				'Bildlink3',
				'Bildlink4',
				'Bildlink5',
				'Bildlink6',
				'Zustand',
				'Familienname1',
				'Eigenschaft1',
				'Familienname2',
				'Eigenschaft2',
				'ID',
				'Einheit',
				'Inhalt',
				'Freifeld1',
				'Freifeld2',
				'Freifeld3',
				'Freifeld4',
				'Freifeld5',
				'Freifeld6',
				'Freifeld7',
				'Freifeld8',
				'Freifeld9',
				'Freifeld10',
				'baseid',
				'basename',
				'level',
				'status',
				'external_categories',
				'base',
				'dealer_price',
				'link',
				'ASIN',
				'Mindestabnahme',
				'Maximalabnahme',
				'Abnahmestaffelung',
				'Energieefiizienz',
				'Energieefiizienzbild',
				'UVP',
				'EVP',
        );
    }
    /**
     * Creates the variation row and prints it into the CSV file.
     *
     * @param array $variation
     * @param KeyValue $settings
     */
    private function buildRow($variation, KeyValue $settings, $attributes)
    {
        $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.variationConstructRow', [
            'Data row duration' => 'Row printing start'
        ]);
        $rowTime = microtime(true);
        // Get the price list
        $priceList = $this->elasticExportPriceHelper->getPriceList($variation, $settings);
        // Only variations with the Retail Price greater than zero will be handled
        if(!is_null($priceList['price']) && $priceList['price'] > 0 && $this->stockHelper->getStock($variation) > 0)
        {
            // Get shipping cost
            $shippingCost = $this->getShippingCost($variation);
            // Get the manufacturer
            $manufacturer = $this->getManufacturer($variation);
            // Get the cross sold items
            $itemCrossSellingList = $this->getItemCrossSellingList($variation);
            // Get base price information list
            $basePriceList = $this->elasticExportHelper->getBasePriceList($variation, (float)$priceList['price'], $settings->get('lang'));
            // Get image list in the specified order
            $imageList = $this->elasticExportHelper->getImageListInOrder($variation, $settings, 6, 'variationImages');
            // Get the flag for the store special
            $flag = $this->getStoreSpecialFlag($variation);
            
            
            
            
            $attributenliste = (strlen($attributes) ? ' | ' . $attributes : '');
            $attributenliste = substr("$attributenliste", 3);
            
            if($attributenliste!=str_replace("Zustand:","",$attributenliste)){
            	$attribut_teil1 = explode("Zustand:", $attributenliste);
				$attribut_teil2 = explode(" | ", $attribut_teil1[1]);
				$zustand = $attribut_teil2[0];
            	
            }elseif($attributenliste!=str_replace("zustand:","",$attributenliste)){
            	$attribut_teil1 = explode("zustand:", $attributenliste);
				$attribut_teil2 = explode(" | ", $attribut_teil1[1]);
				$zustand = $attribut_teil2[0];
            	
            }else{
            	
            	if((int)$variation['data']['item']['conditionApi']['id'] == '0'){
            		$zustand = 'NEU';
            	}elseif((int)$variation['data']['item']['conditionApi']['id'] == '1'){
            		$zustand = 'Gebraucht wie neu';
            	}elseif((int)$variation['data']['item']['conditionApi']['id'] == '2'){
            		$zustand = 'Gebraucht sehr gut';
    		}elseif((int)$variation['data']['item']['conditionApi']['id'] == '3'){
            		$zustand = 'Gebraucht gut';            	
		}elseif((int)$variation['data']['item']['conditionApi']['id'] == '4'){
            		$zustand = 'Gebraucht annehmbar';  
		}elseif((int)$variation['data']['item']['conditionApi']['id'] == '5'){
            		$zustand = 'B-Ware';    
            	}else{
            		$zustand = 'NEU';
            	}
            	
            
            	
            }
            
            
            
            $effizienzklasse = $this->getItemPropertyByExternalComponent($variation, self::RAKUTEN_DE, self::PROPERTY_TYPE_ENERGY_CLASS);
            $effizienzklasse = str_replace("1", "A+++",$effizienzklasse);
            $effizienzklasse = str_replace("2", "A++",$effizienzklasse);
            $effizienzklasse = str_replace("3", "A+",$effizienzklasse);
            $effizienzklasse = str_replace("4", "A",$effizienzklasse);
            $effizienzklasse = str_replace("5", "B",$effizienzklasse);
            $effizienzklasse = str_replace("6", "C",$effizienzklasse);
            $effizienzklasse = str_replace("7", "D",$effizienzklasse);
            $effizienzklasse = str_replace("8", "E",$effizienzklasse);
            $effizienzklasse = str_replace("9", "F",$effizienzklasse);
            $effizienzklasse = str_replace("10", "G",$effizienzklasse);
            
            $basePriceComponentList = $this->getBasePriceComponentList($variation);
            
           
            
            $data = [
            'Produktname'			=> $this->elasticExportHelper->getMutatedName($variation, $settings),
			'Artikelnummer'			=> $variation['data']['variation']['number'],
			'ean'					=> $this->elasticExportHelper->getBarcodeByType($variation, $settings->get('barcode')),
			'Hersteller'			=> $manufacturer,
			'Steuersatz'			=> $priceList['vatValue'],
			'Preis'					=> $priceList['price'],
			'Kurzbeschreibung'		=> $this->elasticExportHelper->getMutatedPreviewText($variation, $settings),
			'Beschreibung'			=> $this->elasticExportHelper->getMutatedDescription($variation, $settings) . ' ' . $this->propertyHelper->getPropertyListDescription($variation, $settings->get('lang')),
			'Versandkosten'			=> $shippingCost,
			'Lagerbestand'			=> $this->stockHelper->getStock($variation),
			'Kategoriestruktur'		=> $this->elasticExportHelper->getCategory((int)$variation['data']['defaultCategories'][0]['id'], (string)$settings->get('lang'), (int)$settings->get('plentyId')),
			'Attribute'				=> '',
			'Gewicht'				=> $variation['data']['variation']['weightG'],
			'Lieferzeit'			=> $this->elasticExportHelper->getAvailability($variation, $settings),
			'Nachnahmegebühr'		=> '',
			'MPN'					=> $variation['data']['variation']['model'],
			'Bildlink'				=> count($imageList) > 0 && array_key_exists(0, $imageList) ? $imageList[0] : '',
			'Bildlink2'				=> count($imageList) > 0 && array_key_exists(1, $imageList) ? $imageList[1] : '',
			'Bildlink3'				=> count($imageList) > 0 && array_key_exists(2, $imageList) ? $imageList[2] : '',
			'Bildlink4'				=> count($imageList) > 0 && array_key_exists(3, $imageList) ? $imageList[3] : '',
			'Bildlink5'				=> count($imageList) > 0 && array_key_exists(4, $imageList) ? $imageList[4] : '',
			'Bildlink6'				=> count($imageList) > 0 && array_key_exists(5, $imageList) ? $imageList[5] : '',
			'Zustand'				=> $zustand,
			'Familienname1'			=> '',
			'Eigenschaft1'			=> '',
			'Familienname2'			=> '',
			'Eigenschaft2'			=> '',
			'ID'					=> $variation['id'],
			'Einheit'				=> $basePriceComponentList['unit'], //$unit,
			'Inhalt'				=> strlen($basePriceComponentList['unit']) ? number_format((float)$basePriceComponentList['content'],3,',','') : '', //$basePriceList['lot'],
			'Freifeld1'				=> $variation['data']['item']['free1'],
			'Freifeld2'				=> $variation['data']['item']['free2'],
			'Freifeld3'				=> $variation['data']['item']['free3'],
			'Freifeld4'				=> $variation['data']['item']['free4'],
			'Freifeld5'				=> $variation['data']['item']['free5'],
			'Freifeld6'				=> $variation['data']['item']['free6'],
			'Freifeld7'				=> $variation['data']['item']['free7'],
			'Freifeld8'				=> $variation['data']['item']['free8'],
			'Freifeld9'				=> $variation['data']['item']['free9'],
			'Freifeld10'			=> $variation['data']['item']['free10'],
			'baseid'				=> 'BASE-'.$variation['data']['item']['id'],
			'basename'				=> $attributenliste,
			'level'					=> '0',
			'status'				=> $this->getStatus($variation),
			'external_categories'	=> '',
			'base'					=> '3',
			'dealer_price'			=> '',
			'link'					=> '',
			'ASIN'					=> '',
			'Mindestabnahme'		=> '',
			'Maximalabnahme'		=> '',
			'Abnahmestaffelung'		=> '',
			'Energieefiizienz'		=> $effizienzklasse,
			'Energieefiizienzbild'	=> '',
			'UVP'					=> $priceList['recommendedRetailPrice'],
			'EVP'					=> '',
            ];
            $this->addCSVContent(array_values($data));
            $this->getLogger(__METHOD__)->debug('ElasticExportlenandoDE::log.variationConstructRowFinished', [
                'Data row duration' => 'Row printing took: ' . (microtime(true) - $rowTime),
            ]);
        }
        else
        {
            $this->getLogger(__METHOD__)->info('ElasticExportlenandoDE::log.variationNotPartOfExportPrice', [
                'VariationId' => (string)$variation['id']
            ]);
        }
    }
    
    
    
    
    
	
    
    // NEU
    
    
    /**
     * Get item characters that match referrer from settings and a given component id.
     * @param  array    $item
     * @param  float    $marketId
     * @param  string   $externalComponent
     * @return string
     */
    private function getItemPropertyByExternalComponent($variation ,float $marketId, $externalComponent):string
    {
        $marketProperties = $this->marketPropertyHelperRepository->getMarketProperty($marketId);
        if(is_array($variation['data']['properties']) && count($variation['data']['properties']) > 0)
        {
            foreach($variation['data']['properties'] as $property)
            {
                foreach($marketProperties as $marketProperty)
                {
                    if(array_key_exists('id', $property['property']))
                    {
                        if(is_array($marketProperty) && count($marketProperty) > 0 && $marketProperty['character_item_id'] == $property['property']['id'])
                        {
                            if (strlen($externalComponent) > 0 && strpos($marketProperty['external_component'], $externalComponent) !== false)
                            {
                                $list = explode(':', $marketProperty['external_component']);
                                if (isset($list[1]) && strlen($list[1]) > 0)
                                {
                                    return $list[1];
                                }
                            }
                        }
                    }
                }
            }
        }
        return '';
    }
    
    // NEU
    
    
    /**
     * Get necessary components to enable Rakuten to calculate a base price for the variation
     * @param array $item
     * @return array
     */
    private function getBasePriceComponentList($variation):array
    {
        $unit = $this->getUnit($variation);
        $content = (float)$variation['data']['unit']['content'];
        $convertBasePriceContentTag = $this->elasticExportHelper->getConvertContentTag($content, 3);
        if ($convertBasePriceContentTag == true && strlen($unit))
        {
            $content = $this->elasticExportHelper->getConvertedBasePriceContent($content, $unit);
            $unit = $this->elasticExportHelper->getConvertedBasePriceUnit($unit);
        }
        return array(
            'content'   =>  $content,
            'unit'      =>  $unit,
        );
    }
    
    
/**
     * Returns the unit, if there is any unit configured, which is allowed
     * for the Rakuten.de API.
     *
     * @param  array   $item
     * @return string
     */
    private function getUnit($variation):string
    {
        switch((int) $variation['data']['unit']['id'])
        {
            case '1':
				return 'Stück';
			case '2':
				return 'kg';
			case '3':
				return 'g';
			case '4':
				return 'mg';
			case '5':
				return 'l';
			case '6':
				return '12 Stück';
			case '7':
				return '2er Pack';
			case '8':
				return 'Ballen';
			case '9':
				return 'Behälter';
			case '10':
				return 'Beutel';
			case '11':
				return 'Blatt';
			case '12':
				return 'Block';
			case '13':
				return 'Block';
			case '14':
				return 'Bogen';
			case '15':
				return 'Box';
			case '16':
				return 'Bund';
			case '17':
				return 'Container';
			case '18':
				return 'Dose';
			case '19':
				return 'Dose/Büchse';
			case '20':
				return 'Dutzend';
			case '21':
				return 'Eimer';
			case '22':
				return 'Etui';
			case '23':
				return 'Fass';
			case '24':
				return 'Flasche';
			case '25':
				return 'Flüssigunze';
			case '26':
				return 'Glas/Gefäß';
			case '27':
				return 'Karton';
			case '28':
				return 'Kartonage';
			case '29':
				return 'Kit';
			case '30':
				return 'Knäuel';
			case '31':
				return 'm';
			case '32':
				return 'ml';
			case '33':
				return 'mm';
			case '34':
				return 'Paar';
			case '35':
				return 'Päckchen';
			case '36':
				return 'Paket';
			case '37':
				return 'Palette';
			case '38':
				return 'm²';
			case '39':
				return 'cm²';
			case '40':
				return 'mm²';
			case '41':
				return 'cm²';
			case '42':
				return 'mm²';
			case '43':
				return 'Rolle';
			case '44':
				return 'Sack';
			case '45':
				return 'Satz';
			case '46':
				return 'Spule';
			case '47':
				return 'Stück';
			case '48':
				return 'Tube/Rohr';
			case '49':
				return 'Unze';
			case '50':
				return 'Wascheinheit';
			case '51':
				return 'cm';
			case '52':
				return 'Zoll';
			
			default:
				return '';
        }
    }
    /**
     * Get the item value for the store special flag.
     *
     * @param $variation
     * @return string
     */
    private function getStoreSpecialFlag($variation):string
    {
        if(!is_null($variation['data']['item']['storeSpecial']) && !is_null($variation['data']['item']['storeSpecial']['id']) && array_key_exists($variation['data']['item']['storeSpecial']['id'], $this->flags))
        {
            return $this->flags[$variation['data']['item']['storeSpecial']['id']];
        }
        return '';
    }
    /**
     * Get status.
     *
     * @param  array $variation
     * @return int
     */
    private function getStatus($variation):int
    {
        if(!array_key_exists($variation['data']['item']['id'], $this->addedItems))
        {
            $this->addedItems[$variation['data']['item']['id']] = $variation['data']['item']['id'];
            return self::STATUS_VISIBLE;
        }
        return self::STATUS_HIDDEN;
    }
    
    /**
     * Get attribute and name value combination for a variation.
     *
     * @param $variation
     * @param KeyValue $settings
     * @return string
     */
    private function getAttributeNameValueCombination($variation, KeyValue $settings):string
    {
        $attributes = '';
        
        $attributeName = $this->elasticExportHelper->getAttributeName($variation, $settings, ' | ');
        
        $attributeValue = $this->elasticExportHelper->getAttributeValueSetShortFrontendName($variation, $settings, ' | ');
        
        
        if(strlen($attributeName) && strlen($attributeValue))
        {
            $attributes = $this->getAttributeNameAndValueCombinations($attributeName, $attributeValue);
        }
        return $attributes;
    }
    
    
    
    
/**
	 * Returns the attribute name and value combination by delimiter.
	 *
     * @param string $attributeNames
     * @param string $attributeValues
     * @param string $delimiter
     * @return string
     */
    public function getAttributeNameAndValueCombinations(string $attributeNames, string $attributeValues, string $delimiter = ' | '):string
    {
        $attributes = '';
        $attributeNameList = array();
        $attributeValueList = array();
        if (strlen($attributeNames) && strlen($attributeValues))
        {
            $attributeNameList = explode(' | ', $attributeNames);
            $attributeValueList = explode(' | ', $attributeValues);
        }
        if (count($attributeNameList) && count($attributeValueList))
        {
            foreach ($attributeNameList as $index => $attributeName)
            {
                if ($index == 0)
                {
                    $attributes .= $attributeNameList[$index]. ':' . $attributeValueList[$index];
                }
                else
                {
                    $attributes .= $delimiter. '' . $attributeNameList[$index]. ':' . $attributeValueList[$index];
                }
            }
        }
        return $attributes;
    }
    
    
    
    
    /**
     * Create the ids list of cross sold items.
     *
     * @param array $variation
     * @return string
     */
    private function createItemCrossSellingList($variation):string
    {
        $list = [];
        $itemCrossSellingList = $this->itemCrossSellingRepository->findByItemId($variation['data']['item']['id']);
        foreach($itemCrossSellingList as $itemCrossSelling)
        {
            $list[] = (string) $itemCrossSelling->crossItemId;
        }
        return implode(', ', $list);
    }
    /**
     * Get the ids list of cross sold items.
     *
     * @param $variation
     * @return string
     */
    private function getItemCrossSellingList($variation):string
    {
        if(isset($this->itemCrossSellingListCache) && array_key_exists($variation['data']['item']['id'], $this->itemCrossSellingListCache))
        {
            return $this->itemCrossSellingListCache[$variation['data']['item']['id']];
        }
        return '';
    }
    /**
     * Get the shipping cost.
     *
     * @param $variation
     * @return string
     */
    private function getShippingCost($variation):string
    {
        $shippingCost = null;
        if(isset($this->shippingCostCache) && array_key_exists($variation['data']['item']['id'], $this->shippingCostCache))
        {
            $shippingCost = $this->shippingCostCache[$variation['data']['item']['id']];
        }
        if(!is_null($shippingCost) && $shippingCost != '0.00')
        {
            return $shippingCost;
        }
        return '';
    }
    /**
     * Get the manufacturer name.
     *
     * @param $variation
     * @return string
     */
    private function getManufacturer($variation):string
    {
        if(isset($this->manufacturerCache) && array_key_exists($variation['data']['item']['manufacturer']['id'], $this->manufacturerCache))
        {
            return $this->manufacturerCache[$variation['data']['item']['manufacturer']['id']];
        }
        return '';
    }
    /**
     * Build the cache arrays for the item variation.
     *
     * @param $variation
     * @param $settings
     */
    private function buildCaches($variation, $settings)
    {
        if(!is_null($variation) && !is_null($variation['data']['item']['id']))
        {
            $shippingCost = $this->elasticExportHelper->getShippingCost($variation['data']['item']['id'], $settings, 0);
            $this->shippingCostCache[$variation['data']['item']['id']] = number_format((float)$shippingCost, 2, '.', '');
            $itemCrossSellingList = $this->createItemCrossSellingList($variation);
            $this->itemCrossSellingListCache[$variation['data']['item']['id']] = $itemCrossSellingList;
            if(!is_null($variation['data']['item']['manufacturer']['id']))
            {
                if(!isset($this->manufacturerCache) || (isset($this->manufacturerCache) && !array_key_exists($variation['data']['item']['manufacturer']['id'], $this->manufacturerCache)))
                {
                    $manufacturer = $this->elasticExportHelper->getExternalManufacturerName((int)$variation['data']['item']['manufacturer']['id']);
                    $this->manufacturerCache[$variation['data']['item']['manufacturer']['id']] = $manufacturer;
                }
            }
        }
    }
}
