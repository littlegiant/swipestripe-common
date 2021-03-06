<?php
declare(strict_types=1);

namespace SwipeStripe\Common\Tests\Product;

use Money\Money;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;
use SwipeStripe\Common\Product\ComplexProduct\ComplexProduct;
use SwipeStripe\Common\Product\ComplexProduct\ComplexProductVariation;
use SwipeStripe\Common\Product\ComplexProduct\ProductAttribute;
use SwipeStripe\Common\Product\ComplexProduct\ProductAttributeOption;
use SwipeStripe\Common\Tests\BaseTest;
use SwipeStripe\Common\Tests\Fixtures\Fixtures;
use SwipeStripe\Order\Order;

/**
 * Class ComplexProductTest
 * @package SwipeStripe\Common\Tests\Product
 */
class ComplexProductTest extends BaseTest
{
    /**
     * @var array
     */
    protected static $fixture_file = [
        Fixtures::COMPLEX_PRODUCTS,
    ];

    /**
     * @var bool
     */
    protected $usesDatabase = true;

    /**
     * @var ComplexProduct
     */
    protected $tshirt;

    /**
     *
     */
    public function testVariationPrice()
    {
        /** @var ComplexProductVariation $smallRed */
        $smallRed = $this->objFromFixture(ComplexProductVariation::class, 'tshirt-small-red');
        $this->assertTrue($smallRed->getBasePrice()->getMoney()->equals(new Money(1000, $this->currency)));

        /** @var ComplexProductVariation $smallGold */
        $smallGold = $this->objFromFixture(ComplexProductVariation::class, 'tshirt-small-gold');
        $this->assertTrue($smallGold->getBasePrice()->getMoney()->equals(new Money(1500, $this->currency)));

        /** @var ComplexProductVariation $largeRed */
        $largeRed = $this->objFromFixture(ComplexProductVariation::class, 'tshirt-large-red');
        $this->assertTrue($largeRed->getBasePrice()->getMoney()->equals(new Money(1200, $this->currency)));

        /** @var ComplexProductVariation $largeGold */
        $largeGold = $this->objFromFixture(ComplexProductVariation::class, 'tshirt-large-gold');
        $this->assertTrue($largeGold->getBasePrice()->getMoney()->equals(new Money(1700, $this->currency)));

        /** @var ProductAttributeOption $goldColour */
        $goldColour = $this->objFromFixture(ProductAttributeOption::class, 'tshirt-colour-gold');
        $goldColour->PriceModifier->Amount = 1000;
        $goldColour->writeToStage(Versioned::DRAFT);

        // No price change before publish
        $this->assertTrue($smallGold->getBasePrice()->getMoney()->equals(new Money(1500, $this->currency)));
        $this->assertTrue($largeGold->getBasePrice()->getMoney()->equals(new Money(1700, $this->currency)));

        $goldColour->publishRecursive();
        $this->assertTrue($smallGold->getBasePrice()->getMoney()->equals(new Money(2000, $this->currency)));
        $this->assertTrue($largeGold->getBasePrice()->getMoney()->equals(new Money(2200, $this->currency)));
    }

    /**
     *
     */
    public function testVariationPriceLocking()
    {
        /** @var ComplexProductVariation $smallRed */
        $smallRed = $this->objFromFixture(ComplexProductVariation::class, 'tshirt-small-red');
        $smallRedOriginalPrice = $smallRed->getBasePrice()->getMoney();

        $cart = Order::singleton()->createCart();
        $cart->addItem($smallRed);
        $this->assertTrue($cart->Total()->getMoney()->equals($smallRedOriginalPrice));

        $cart->Lock();
        $this->mockWait();

        $tshirt = $smallRed->Product();
        $tshirt->BasePrice->Amount = 1500;
        $tshirt->writeToStage(Versioned::LIVE);

        // Live variation's price should update
        $this->assertTrue($smallRed->getBasePrice()->getMoney()->equals(new Money(1500, $this->currency)));

        // Locked item's price should not change
        $this->assertTrue($cart->Total()->getMoney()->equals($smallRedOriginalPrice));

        // Unlocking should update to live price
        $cart->Unlock();
        $this->assertTrue($cart->Total()->getMoney()->equals(new Money(1500, $this->currency)));
    }

    /**
     * @throws \Exception
     */
    public function testGetVariationsWithOptions()
    {
        $smallVariationIds = [
            $this->idFromFixture(ComplexProductVariation::class, 'tshirt-small-red'),
            $this->idFromFixture(ComplexProductVariation::class, 'tshirt-small-gold'),
        ];
        sort($smallVariationIds);

        $variationIds = ComplexProductVariation::getVariationsWithOptions($this->tshirt,
            [$this->idFromFixture(ProductAttributeOption::class, 'tshirt-size-small')])
            ->sort('ID', 'ASC')
            ->column();
        $this->assertSame($smallVariationIds, $variationIds);

        $this->assertEmpty(ComplexProductVariation::getVariationsWithOptions($this->tshirt,
            [$this->idFromFixture(ProductAttributeOption::class, 'tshirt-size-unused')]));
    }

    /**
     *
     */
    public function testTwoOptionsForSameAttributeInVariation()
    {
        /** @var ComplexProductVariation $smallRed */
        $smallRed = $this->objFromFixture(ComplexProductVariation::class, 'tshirt-small-red');

        // Should fail validation if we add a second selection for the same attribute (size) to this variation
        $this->expectException(ValidationException::class);
        $smallRed->ProductAttributeOptions()->add($this->idFromFixture(ProductAttributeOption::class,
            'tshirt-size-unused'));
    }

    /**
     *
     */
    public function testDuplicateVariation()
    {
        /** @var ComplexProductVariation $smallRed */
        $smallRed = $this->objFromFixture(ComplexProductVariation::class, 'tshirt-small-red');

        $duplicate = ComplexProductVariation::create();
        $duplicate->setComponent('Product', $smallRed->Product());
        $duplicate->write();

        // Should fail validation if we add a duplicate of the exact same option combos
        $this->expectException(ValidationException::class);
        $duplicate->ProductAttributeOptions()->addMany($smallRed->ProductAttributeOptions());
    }

    /**
     *
     */
    public function testGetVariationWithExactOptions()
    {
        $this->assertNull(ComplexProductVariation::getVariationWithExactOptions($this->tshirt, [
            $this->idFromFixture(ProductAttributeOption::class, 'tshirt-size-unused'),
        ]));

        $this->assertNull(ComplexProductVariation::getVariationWithExactOptions($this->tshirt, [
            $this->idFromFixture(ProductAttributeOption::class, 'tshirt-colour-red'),
            $this->idFromFixture(ProductAttributeOption::class, 'tshirt-size-unused'),
        ]));

        $smallRed = ComplexProductVariation::getVariationWithExactOptions($this->tshirt, [
            $this->idFromFixture(ProductAttributeOption::class, 'tshirt-colour-red'),
            $this->idFromFixture(ProductAttributeOption::class, 'tshirt-size-small'),
        ]);

        $this->assertNotNull($smallRed);
        $this->assertSame($this->idFromFixture(ComplexProductVariation::class, 'tshirt-small-red'),
            $smallRed->ID);
    }

    /**
     *
     */
    public function testVariationGetOptionsForUnselectedAttributes()
    {
        /** @var ProductAttributeOption $sizeUnused */
        $sizeUnused = $this->objFromFixture(ProductAttributeOption::class, 'tshirt-size-unused');

        $variation = ComplexProductVariation::create();
        $variation->setComponent('Product', $this->tshirt)
            ->write();
        $variation->ProductAttributeOptions()->add($sizeUnused);

        $availableOptions = $variation->getOptionsForUnselectedAttributes()->sort('ID', 'ASC')->column();
        $expectedOptionIds = [
            $this->idFromFixture(ProductAttributeOption::class, 'tshirt-colour-red'),
            $this->idFromFixture(ProductAttributeOption::class, 'tshirt-colour-gold'),
        ];
        sort($expectedOptionIds);
        $this->assertSame($expectedOptionIds, $availableOptions);

        // No options to add when all attributes have a value
        $variation->ProductAttributeOptions()->add($expectedOptionIds[0]);
        $this->assertCount(0, $variation->getOptionsForUnselectedAttributes());
    }

    /**
     *
     */
    public function testVariationLink()
    {
        /** @var ComplexProductVariation $variation */
        $variation = ComplexProductVariation::get_one(ComplexProductVariation::class);
        $this->assertSame($variation->Product()->Link(), $variation->Link());
    }

    /**
     *
     */
    public function testIsOutOfStock()
    {
        $this->assertFalse($this->tshirt->IsOutOfStock());

        $this->tshirt->OutOfStock = true;
        $this->assertTrue($this->tshirt->IsOutOfStock());
    }

    /**
     *
     */
    public function testIsOutOfStockNoVariations()
    {
        $product = ComplexProduct::create();
        $product->write();

        $this->assertTrue($product->IsOutOfStock());
    }

    /**
     *
     */
    public function testIsOutOfStockExtension()
    {
        ComplexProduct::add_extension(OutOfStockExtension::class);
        /** @var ComplexProduct $tshirtNewInstance */
        $tshirtNewInstance = ComplexProduct::get()->byID($this->tshirt->ID);

        $this->assertTrue($tshirtNewInstance->IsOutOfStock());
    }

    /**
     *
     */
    public function testIsOutOfStockVariationsAllOutOfStock()
    {
        ComplexProductVariation::add_extension(OutOfStockExtension::class);
        $this->assertTrue($this->tshirt->IsOutOfStock());
    }

    /**
     * @inheritDoc
     */
    protected function setUp()
    {
        static::registerPublishingBlueprint(ComplexProduct::class);
        static::registerPublishingBlueprint(ProductAttribute::class);
        static::registerPublishingBlueprint(ProductAttributeOption::class);
        static::registerPublishingBlueprint(ComplexProductVariation::class);

        parent::setUp();

        $this->tshirt = $this->objFromFixture(ComplexProduct::class, 'tshirt');;
    }
}
