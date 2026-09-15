<?php

declare(strict_types=1);

namespace Drupal\Tests\price_difference_formatter\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_promotion\Entity\Promotion;
use Drupal\commerce_tax\Entity\TaxType;
use Drupal\price_difference_formatter\Plugin\Field\FieldFormatter\PriceDifferenceFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the price difference field formatter.
 */
#[CoversClass(PriceDifferenceFormatter::class)]
#[Group('price_difference_formatter')]
#[RunTestsInSeparateProcesses]
class PriceDifferenceFormatterTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_promotion',
    'commerce_tax',
    'price_difference_formatter',
  ];

  /**
   * A product variation priced at $3.00.
   *
   * @var \Drupal\commerce_product\Entity\ProductVariationInterface
   */
  protected $variation;

  /**
   * The commerce_product_variation view builder.
   *
   * @var \Drupal\Core\Entity\EntityViewBuilderInterface
   */
  protected $viewBuilder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_promotion');
    $this->installSchema('commerce_promotion', ['commerce_promotion_usage']);

    $this->store->set('tax_registrations', ['US']);
    $this->store->save();

    // A 50% off promotion applied to every order item.
    Promotion::create([
      'name' => 'Half price',
      'order_types' => ['default'],
      'stores' => [$this->store->id()],
      'status' => TRUE,
      'offer' => [
        'target_plugin_id' => 'order_item_percentage_off',
        'target_plugin_configuration' => [
          'percentage' => '0.5',
        ],
      ],
    ])->save();

    // The default store is in US-WI: a 20% display-inclusive tax.
    TaxType::create([
      'id' => 'us_vat',
      'label' => 'US VAT',
      'plugin' => 'custom',
      'configuration' => [
        'display_inclusive' => TRUE,
        'rates' => [
          [
            'id' => 'standard',
            'label' => 'Standard',
            'percentage' => '0.2',
          ],
        ],
        'territories' => [
          ['country_code' => 'US', 'administrative_area' => 'WI'],
        ],
      ],
    ])->save();

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'TEST_PRICE_DIFFERENCE',
      'status' => 1,
      'price' => new Price('3.00', 'USD'),
    ]);
    $variation->save();
    Product::create([
      'type' => 'default',
      'title' => 'Test product',
      'stores' => [$this->store->id()],
      'variations' => [$variation],
    ])->save();
    $this->variation = $this->reloadEntity($variation);

    $user = $this->createUser();
    $this->container->get('current_user')->setAccount($user);

    $this->viewBuilder = $this->container->get('entity_type.manager')->getViewBuilder('commerce_product_variation');
  }

  /**
   * Renders the variation price field with the given formatter settings.
   *
   * @param array $settings
   *   The formatter settings.
   *
   * @return string
   *   The rendered HTML.
   */
  protected function renderVariationPrice(array $settings): string {
    $display = commerce_get_entity_display('commerce_product_variation', 'default', 'view');
    $display->setComponent('price', [
      'label' => 'hidden',
      'type' => 'price_difference_formatter',
      'settings' => $settings,
    ]);
    $display->save();

    $build = $this->viewBuilder->view($this->variation);
    return (string) $this->render($build);
  }

  /**
   * Tests that without a promotion only the final price is shown.
   */
  public function testRenderWithoutDiscount(): void {
    $output = $this->renderVariationPrice([]);

    $this->assertStringContainsString('<div class="final-price">$3.00</div>', $output);
    $this->assertStringNotContainsString('original-price', $output);
    $this->assertStringNotContainsString('discount-percentage', $output);
    $this->assertStringNotContainsString('discount-currency', $output);
    $this->assertStringNotContainsString('price-with-discount', $output);
  }

  /**
   * Tests the original price, final price and default discount percentage.
   */
  public function testRenderWithPromotion(): void {
    // No display_elements given: the percentage is shown by default.
    $output = $this->renderVariationPrice([
      'adjustment_types' => ['promotion' => 'promotion'],
    ]);

    $this->assertStringContainsString('price-with-discount', $output);
    $this->assertStringContainsString('<s>$3.00</s>', $output);
    $this->assertStringContainsString('<div class="discount-percentage">- 50%</div>', $output);
    $this->assertStringContainsString('<div class="final-price">$1.50</div>', $output);
    $this->assertStringNotContainsString('discount-currency', $output);
  }

  /**
   * Tests the display_elements setting.
   */
  public function testDisplayElements(): void {
    $cases = [
      'currency only' => [
        'display_elements' => ['discount_currency' => 'discount_currency'],
        'expected' => ['<div class="discount-currency">- $1.50</div>'],
        'not_expected' => ['discount-percentage'],
      ],
      'percentage and currency' => [
        'display_elements' => [
          'discount_percentage' => 'discount_percentage',
          'discount_currency' => 'discount_currency',
        ],
        'expected' => [
          '<div class="discount-percentage">- 50%</div>',
          '<div class="discount-currency">- $1.50</div>',
        ],
        'not_expected' => [],
      ],
      'none' => [
        'display_elements' => [
          'discount_percentage' => 0,
          'discount_currency' => 0,
        ],
        'expected' => ['<s>$3.00</s>', '<div class="final-price">$1.50</div>'],
        'not_expected' => [
          'discount-percentage',
          'discount-currency',
          'price-with-discount',
        ],
      ],
    ];
    foreach ($cases as $name => $case) {
      $output = $this->renderVariationPrice([
        'adjustment_types' => ['promotion' => 'promotion'],
        'display_elements' => $case['display_elements'],
      ]);
      foreach ($case['expected'] as $expected) {
        $this->assertStringContainsString($expected, $output, $name);
      }
      foreach ($case['not_expected'] as $not_expected) {
        $this->assertStringNotContainsString($not_expected, $output, $name);
      }
    }
  }

  /**
   * Tests that the original price keeps the other selected adjustments.
   */
  public function testRenderWithTaxAndPromotion(): void {
    $output = $this->renderVariationPrice([
      'adjustment_types' => ['tax' => 'tax', 'promotion' => 'promotion'],
      'display_elements' => [
        'discount_percentage' => 'discount_percentage',
        'discount_currency' => 'discount_currency',
      ],
    ]);

    // The original price includes the tax but not the promotion.
    $this->assertStringContainsString('<s>$3.60</s>', $output);
    $this->assertStringContainsString('<div class="discount-percentage">- 50%</div>', $output);
    $this->assertStringContainsString('<div class="discount-currency">- $1.80</div>', $output);
    $this->assertStringContainsString('<div class="final-price">$1.80</div>', $output);

    // Tax only: nothing to discount.
    $output = $this->renderVariationPrice([
      'adjustment_types' => ['tax' => 'tax'],
    ]);
    $this->assertStringContainsString('<div class="final-price">$3.60</div>', $output);
    $this->assertStringNotContainsString('original-price', $output);
    $this->assertStringNotContainsString('discount-percentage', $output);
  }

  /**
   * Tests rendering order item prices, where total_price uses the quantity.
   */
  public function testRenderOrderItem(): void {
    $order_item = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $this->variation,
      'quantity' => '2',
      'unit_price' => new Price('3.00', 'USD'),
    ]);
    $order_item->save();
    $display_options = [
      'type' => 'price_difference_formatter',
      'label' => 'hidden',
      'settings' => [
        'adjustment_types' => ['promotion' => 'promotion'],
        'display_elements' => [
          'discount_percentage' => 'discount_percentage',
          'discount_currency' => 'discount_currency',
        ],
      ],
    ];

    $build = $order_item->get('total_price')->view($display_options);
    $output = (string) $this->render($build);
    $this->assertStringContainsString('<s>$6.00</s>', $output);
    $this->assertStringContainsString('<div class="discount-percentage">- 50%</div>', $output);
    $this->assertStringContainsString('<div class="discount-currency">- $3.00</div>', $output);
    $this->assertStringContainsString('<div class="final-price">$3.00</div>', $output);

    $build = $order_item->get('unit_price')->view($display_options);
    $output = (string) $this->render($build);
    $this->assertStringContainsString('<s>$3.00</s>', $output);
    $this->assertStringContainsString('<div class="discount-currency">- $1.50</div>', $output);
    $this->assertStringContainsString('<div class="final-price">$1.50</div>', $output);
  }

  /**
   * Tests the settings summary.
   */
  public function testSettingsSummary(): void {
    $field_definition = $this->container->get('entity_field.manager')
      ->getBaseFieldDefinitions('commerce_product_variation')['price'];
    /** @var \Drupal\Core\Field\FormatterPluginManager $formatter_manager */
    $formatter_manager = $this->container->get('plugin.manager.field.formatter');

    $cases = [
      'defaults' => [
        'settings' => [],
        'expected' => ['Display The discount percentage amount'],
        'not_expected' => ['Display The discount currency amount'],
      ],
      'currency only' => [
        'settings' => [
          'display_elements' => [
            'discount_percentage' => 0,
            'discount_currency' => 'discount_currency',
          ],
        ],
        'expected' => ['Display The discount currency amount'],
        'not_expected' => ['Display The discount percentage amount'],
      ],
    ];
    foreach ($cases as $name => $case) {
      $formatter = $formatter_manager->getInstance([
        'field_definition' => $field_definition,
        'view_mode' => 'default',
        'configuration' => [
          'type' => 'price_difference_formatter',
          'settings' => $case['settings'],
        ],
      ]);
      $this->assertInstanceOf(PriceDifferenceFormatter::class, $formatter, $name);
      $summary = implode("\n", array_map('strval', $formatter->settingsSummary()));
      foreach ($case['expected'] as $expected) {
        $this->assertStringContainsString($expected, $summary, $name);
      }
      foreach ($case['not_expected'] as $not_expected) {
        $this->assertStringNotContainsString($not_expected, $summary, $name);
      }
    }
  }

  /**
   * Tests that the formatter applies to purchasable entities and order items.
   */
  public function testIsApplicable(): void {
    $field_manager = $this->container->get('entity_field.manager');

    $variation_price = $field_manager->getBaseFieldDefinitions('commerce_product_variation')['price'];
    $this->assertTrue(PriceDifferenceFormatter::isApplicable($variation_price));

    $order_item_price = $field_manager->getBaseFieldDefinitions('commerce_order_item')['unit_price'];
    $this->assertTrue(PriceDifferenceFormatter::isApplicable($order_item_price));

    $user_price = BaseFieldDefinition::create('commerce_price')
      ->setName('price')
      ->setTargetEntityTypeId('user');
    $this->assertFalse(PriceDifferenceFormatter::isApplicable($user_price));
  }

}
