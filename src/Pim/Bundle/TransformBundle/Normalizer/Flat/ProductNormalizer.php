<?php

namespace Pim\Bundle\TransformBundle\Normalizer\Flat;

use Symfony\Component\Serializer\Normalizer\SerializerAwareNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Pim\Bundle\CatalogBundle\Entity\Family;
use Pim\Bundle\CatalogBundle\Entity\Group;
use Pim\Bundle\CatalogBundle\Model\ProductInterface;
use Pim\Bundle\TransformBundle\Normalizer\Filter\NormalizerFilterInterface;
use Pim\Bundle\CatalogBundle\Model\AbstractProductValue;

/**
 * A normalizer to transform a product entity into a flat array
 *
 * @author    Gildas Quemener <gildas@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class ProductNormalizer extends SerializerAwareNormalizer implements NormalizerInterface
{
    /** @staticvar string */
    const FIELD_FAMILY = 'family';

    /** @staticvar string */
    const FIELD_GROUPS = 'groups';

    /** @staticvar string */
    const FIELD_CATEGORY = 'categories';

    /** @staticvar string */
    const ITEM_SEPARATOR = ',';

    /** @var array */
    protected $supportedFormats = array('csv', 'flat');

    /** @var array */
    protected $results = array();

    /** @var array $fields */
    protected $fields = array();

    /** @var NormalizerFilterInterface[] */
    protected $valuesFilters = [];

    /** @var integer */
    protected $precision;

    /**
     * @param integer $precision
     */
    public function __construct($precision = 4)
    {
        $this->precision = $precision;
    }

    /**
     * {@inheritdoc}
     */
    public function setFilters(array $filters)
    {
        $this->valuesFilters = $filters;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($object, $format = null, array $context = array())
    {
        $context = $this->resolveContext($context);

        if (isset($context['fields']) && !empty($context['fields'])) {
            $this->fields  = array_fill_keys($context['fields'], '');
            $this->results = $this->fields;
        } else {
            $this->results = $this->normalizeValue($object->getIdentifier(), $format, $context);
        }

        $this->normalizeFamily($object->getFamily());

        $this->normalizeGroups($object->getGroupCodes());

        $this->normalizeCategories($object->getCategoryCodes());

        $this->normalizeAssociations($object->getAssociations());

        $this->normalizeValues($object, $format, $context);

        $this->normalizeProperties($object);

        return $this->results;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null)
    {
        return $data instanceof ProductInterface && in_array($format, $this->supportedFormats);
    }

    /**
     * Normalize properties
     *
     * @param ProductInterface $product
     */
    protected function normalizeProperties(ProductInterface $product)
    {
        $this->results['enabled'] = (int) $product->isEnabled();
    }

    /**
     * Normalize values
     *
     * @param ProductInterface $product
     * @param string|null      $format
     * @param array            $context
     *
     * @return null
     */
    protected function normalizeValues(ProductInterface $product, $format = null, array $context = [])
    {
        if (empty($this->fields)) {

            $filteredValues = array();
            $normalizedValues = array();

            foreach ($this->valuesFilters as $filter) {
                $filteredValues = $filter->filter(
                    $product->getValues(),
                    array(
                        'identifier'  => $product->getIdentifier(),
                        'scopeCode'   => $context['scopeCode'],
                        'localeCodes' => $context['localeCodes'],
                    )
                );
            }

            foreach ($filteredValues as $value) {
                $normalizedValues = array_merge(
                    $normalizedValues,
                    $this->normalizeValue($value, $format, $context)
                );
            }
            ksort($normalizedValues);

            $this->results = array_merge($this->results, $normalizedValues);

        } else {
            foreach ($product->getValues() as $value) {
                $fieldValue = $this->getFieldValue($value);
                if (isset($this->fields[$fieldValue])) {
                    $normalizedValue = $this->normalizeValue($value, $format, $context);
                    $this->results = array_merge($this->results, $normalizedValue);
                }
            }
        }
    }

    /**
     * Normalizes a value
     *
     * @param AbstractProductValue $value
     * @param string               $format
     * @param array                $context
     *
     * @return array
     */
    protected function normalizeValue(AbstractProductValue $value, $format = null, array $context = [])
    {
        $data = $value->getData();
        $fieldName = $this->getFieldValue($value);

        /**
         * For performance purpose, Symfony serializer doesn't allow to normalize differently an object
         * for the same format.
         * I.e. for the csv format, the same normalizer will be used to normalize all instance of AbstractProductValue.
         *
         * That's why we need to normalize the product value *data*, instead of the product value itself.
         * Because of that, and because Symfony serializer only serializes object data
         * (other data are normalized as they are given, see
         * https://github.com/symfony/Serializer/blob/2.3/Serializer.php#L107),
         * Cases where product value data is null or a scalar need to be handled manually here and not
         * through other normalizers.
         */
        $result = null;

        if (is_array($data)) {
            $data = new ArrayCollection($data);
        }

        if (is_null($data)) {
            $result = [$fieldName => ''];
        } elseif (is_int($data)) {
            $result = [$fieldName => (string) $data];
        } elseif (is_float($data)) {
            $result = [$fieldName => sprintf(sprintf('%%.%sF', $this->precision), $data)];
        } elseif (is_string($data)) {
            $result = [$fieldName => $data];
        } elseif (is_bool($data)) {
            $result = [$fieldName => (string) (int) $data];
        } elseif (is_object($data)) {
            $context['field_name'] = $fieldName;
            $context['metric_format'] = empty($this->fields) ? 'multiple_fields' : 'single_field';
            $result = $this->serializer->normalize($data, $format, $context);
        }

        if (null === $result) {
            throw new \RuntimeException(
                sprintf(
                    'Cannot normalize product value "%s" which data is a(n) "%s"',
                    $fieldName,
                    is_object($data) ? get_class($data) : gettype($data)
                )
            );
        }

        return $result;
    }

    /**
     * Normalize the field name for values
     *
     * @param ProductValueInterface $value
     *
     * @return string
     */
    protected function getFieldValue($value)
    {
        $suffix = '';

        if ($value->getAttribute()->isLocalizable()) {
            $suffix = sprintf('-%s', $value->getLocale());
        }
        if ($value->getAttribute()->isScopable()) {
            $suffix .= sprintf('-%s', $value->getScope());
        }

        return $value->getAttribute()->getCode() . $suffix;
    }

    /**
     * Normalizes a family
     *
     * @param Family $family
     */
    protected function normalizeFamily(Family $family = null)
    {
        $this->results[self::FIELD_FAMILY] = $family ? $family->getCode() : '';
    }

    /**
     * Normalizes groups
     *
     * @param Group[] $groups
     */
    protected function normalizeGroups($groups = null)
    {
        $this->results[self::FIELD_GROUPS] = $groups;
    }

    /**
     * Normalizes categories
     *
     * @param string $categories
     */
    protected function normalizeCategories($categories = '')
    {
        $this->results[self::FIELD_CATEGORY] = $categories;
    }

    /**
     * Normalize associations
     *
     * @param Association[] $associations
     */
    protected function normalizeAssociations($associations = array())
    {
        foreach ($associations as $association) {
            $columnPrefix = $association->getAssociationType()->getCode();

            $groups = array();
            foreach ($association->getGroups() as $group) {
                $groups[] = $group->getCode();
            }

            $products = array();
            foreach ($association->getProducts() as $product) {
                $products[] = $product->getIdentifier();
            }

            $this->results[$columnPrefix .'-groups'] = implode(',', $groups);
            $this->results[$columnPrefix .'-products'] = implode(',', $products);
        }
    }

    /**
     * Merge default format option with context
     *
     * @param array $context
     *
     * @return array
     */
    protected function resolveContext(array $context)
    {
        return array_merge(['scopeCode' => null, 'localeCodes' => []], $context);
    }
}
