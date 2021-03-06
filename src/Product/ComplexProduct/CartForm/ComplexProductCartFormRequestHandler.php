<?php
declare(strict_types=1);

namespace SwipeStripe\Common\Product\ComplexProduct\CartForm;

use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\FormRequestHandler;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\ValidationResult;
use SwipeStripe\Common\Product\ComplexProduct\ComplexProductVariation;
use SwipeStripe\HasActiveCart;

/**
 * Class ComplexProductCartFormRequestHandler
 * @package SwipeStripe\Common\Product\ComplexProduct\CartForm
 * @property ComplexProductCartForm $form
 */
class ComplexProductCartFormRequestHandler extends FormRequestHandler
{
    use HasActiveCart;

    /**
     * @inheritDoc
     */
    public function __construct(ComplexProductCartFormInterface $form)
    {
        parent::__construct($form);
    }

    /**
     * @param array $data
     * @param ComplexProductCartFormInterface $form
     * @return HTTPResponse
     */
    public function AddToCart(array $data, ComplexProductCartFormInterface $form): HTTPResponse
    {
        $ids = [];
        foreach ($form->Fields()->dataFields() as $dataField) {
            if ($dataField instanceof ProductAttributeField) {
                $ids[] = intval($dataField->dataValue());
            }
        }

        $quantityField = $form->Fields()->dataFieldByName(ComplexProductCartForm::QUANTITY_FIELD);
        $variation = ComplexProductVariation::getVariationWithExactOptions($form->getProduct(), $ids);

        if ($variation === null) {
            throw ValidationException::create(ValidationResult::create()
                ->addError(_t(self::class . '.VARIATION_UNAVAILABLE',
                    'Sorry, that combination of options is not available.')));
        } elseif ($variation->IsOutOfStock()) {
            throw ValidationException::create(ValidationResult::create()
                ->addError(_t(self::class . '.VARIATION_OUT_OF_STOCK',
                    'Sorry, that combination of options is currently out of stock.')));
        }

        $this->ActiveCart->addItem($variation, $quantityField->dataValue());

        return $this->redirectBack();
    }
}
