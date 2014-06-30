<?php

namespace Pim\Bundle\TransformBundle\Transformer\MongoDB;

use Pim\Bundle\TransformBundle\Transformer\ObjectTransformerInterface;
use Pim\Bundle\CatalogBundle\Model\ProductValueInterface;

use Doctrine\Common\Collections\Collection;

use \MongoId;
use \MongoDBRef;

/**
 * Transform a product value into a MongoDB embedded document
 *
 * @author    Benoit Jacquemont <filips@akeneo.com>
 * @copyright 2014 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class ProductValueTransformer implements ObjectTransformerInterface
{
    /** @var MediaTransformer */
    protected $mediaTransformer;

    /** @var DateTimeTransformer */
    protected $dateTransformer;

    /** @var MetricTransformer */
    protected $metricTransformer;

    /** @var PriceTransformer */
    protected $priceTransformer;

    /**
     * @param DateTimeTransformer $dateTransformer
     * @param MetricTransformer   $metricTransformer
     * @param PriceTransformer    $priceTransformer
     */
    public function __construct(
        DateTimeTransformer  $dateTransformer,
        MetricTransformer $metricTransformer,
        PriceTransformer $priceTransformer
    ) {
        $this->dateTransformer   = $dateTransformer;
        $this->metricTransformer = $metricTransformer;
        $this->priceTransformer  = $priceTransformer;
    }

    /**
     * {@inheritdoc}
     */
    public function transform($value, array $context = [])
    {
        if (null === $value->getData()) {
            return null;
        }

        $productId = $context[ProductTransformer::MONGO_ID];
        $productCollection = $context[ProductTransformer::MONGO_COLLECTION_NAME];

        $targetValue = new \StdClass();
        $targetValue->_id = new MongoId();
        $targetValue->attribute = $value->getAttribute()->getId();
        $targetValue->entity = MongoDBRef::create($productCollection, $productId);

        if (null !== $value->getLocale()) {
            $targetValue->locale = $value->getLocale();
        }
        if (null !== $value->getScope()) {
            $targetValue->scope = $value->getScope();
        }

        $data = $value->getData();
        $backendType = $value->getAttribute()->getBackendType();

        $targetValue->$backendType = $this->transformData($data, $backendType, $context);

        return $targetValue;
    }

    /**
     * Transform data from a value
     *
     * @param mixed  $data
     * @param string $backendType
     * @param array  $context
     *
     * @return mixed
     */
    protected function transformData($data, $backendType, array $context)
    {
        $targetData = null;

        if (is_array($data) || $data instanceof Collection) {
            $targetData = array();
            foreach ($data as $dataItem) {
                if (is_object($dataItem)) {
                    $targetData[] = $this->transformDataObject($dataItem, $backendType, $context);
                } else {
                    $targetData[] = $dataItem;
                }
            }
        } elseif (is_object($data)) {
            $targetData = $this->transformDataObject($data, $backendType, $context);
        } else {
            $targetData = $data;
        }

        return $targetData;
    }

    /**
     * Transform data object
     *
     * @param object $dataObject
     * @param string $backendType
     * @param array  $context
     *
     * @return object
     */
    protected function transformDataObject($dataObject, $backendType, array $context)
    {
        $target = null;

        switch($backendType) {
            case "prices":
                $target = $this->priceTransformer->transform($dataObject, $context);
                break;
            case "date":
                $target = $this->dateTransformer->transform($dataObject, $context);
                break;
            case "media":
                $target = $this->mediaTransformer->transform($dataObject, $context);
                break;
            case "metric":
                $target = $this->metricTransformer->transform($dataObject, $context);
                break;
            default:
                $target = $dataObject->getId();
                break;
        }

        return $target;
    }
}
