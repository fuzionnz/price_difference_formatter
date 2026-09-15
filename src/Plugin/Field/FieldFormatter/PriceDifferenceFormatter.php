<?php

namespace Drupal\price_difference_formatter\Plugin\Field\FieldFormatter;

use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\commerce_order\Plugin\Field\FieldFormatter\PriceCalculatedFormatter;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'price_difference_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "price_difference_formatter",
 *   label = @Translation("Price Difference Formatter"),
 *   field_types = {
 *     "commerce_price"
 *   }
 * )
 */
class PriceDifferenceFormatter extends PriceCalculatedFormatter implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    if (!$items->isEmpty()) {
      $context = new Context($this->currentUser, $this->currentStore->getStore(), NULL, [
      // 'field_name' => $items->getName(),
      ]);
      /** @var \Drupal\commerce\PurchasableEntityInterface $purchasable_entity */
      $purchasable_entity = $items->getEntity();
      $quantity = 1;
      if ($purchasable_entity instanceof OrderItemInterface) {
        if ($items->getName() === 'total_price') {
          $quantity = $purchasable_entity->getQuantity();
        }
        $purchasable_entity = $purchasable_entity->getPurchasedEntity();
      }
      // Order items whose purchased entity was deleted (abandoned carts, for
      // example) have nothing to calculate a price for.
      if (!$purchasable_entity instanceof PurchasableEntityInterface) {
        return $elements;
      }

      $adjustment_types = array_filter($this->getSetting('adjustment_types'));

      // Get the adjusted price.
      $result = $this->priceCalculator->calculate($purchasable_entity, $quantity, $context, $adjustment_types);
      $calculated_price = $result->getCalculatedPrice();
      $number = $calculated_price->getNumber();

      $currency_code = $calculated_price->getCurrencyCode();
      $options = $this->getFormattingOptions();

      // Get the price without the promotion adjustment, but still include
      // any other adjustments that were selected.
      unset($adjustment_types['promotion']);

      $result_without_promotion = $this->priceCalculator->calculate($purchasable_entity, $quantity, $context, $adjustment_types);
      $calculated_price_without_promotion = $result_without_promotion->getCalculatedPrice();
      $number_without_promotion = $calculated_price_without_promotion->getNumber();

      if ($number_without_promotion != $number) {
        $difference = $number_without_promotion - $number;
        $percentage = $difference / $number_without_promotion;
      }

      // Get the display settings for the formatter.
      $display_elements = array_filter($this->getSetting('display_elements'));

      $elements[0] = [
        '#theme' => 'price_difference_formatter',
        '#final_price' => $this->currencyFormatter->format($number, $currency_code, $options),
        '#original_price' => $this->currencyFormatter->format($number_without_promotion, $currency_code, $options),
        '#discount_percentage' => (isset($percentage) && isset($display_elements['discount_percentage'])) ? round($percentage * 100, 2) . '%' : NULL,
        '#discount_currency' => (isset($difference) && isset($display_elements['discount_currency'])) ? $this->currencyFormatter->format($difference, $currency_code, $options) : NULL,
        '#adjustments' => $result->getAdjustments(),
        '#cache' => [
          'tags' => $purchasable_entity->getCacheTags(),
          'contexts' => Cache::mergeContexts($purchasable_entity->getCacheContexts(), [
            'languages:' . LanguageInterface::TYPE_INTERFACE,
            'country',
          ]),
        ],
      ];
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'display_elements' => [
        'discount_percentage' => 'discount_percentage',
      ],
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['display_elements'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Display Elements'),
      '#options' => $this->getDisplayOptions(),
      '#default_value' => $this->getSetting('display_elements'),
    ];

    return $elements;
  }

  /**
   * Gets the options for the fields display elements.
   *
   * @return array
   *   An array of options.
   */
  public function getDisplayOptions() {
    return [
      'discount_percentage' => $this->t('The discount percentage amount'),
      'discount_currency' => $this->t('The discount currency amount'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $options = $this->getDisplayOptions();
    foreach (array_filter($this->getSetting('display_elements')) as $key) {
      $summary[] = $this->t('Display @label', ['@label' => $options[$key]]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    $entity_type = \Drupal::entityTypeManager()->getDefinition($field_definition->getTargetEntityTypeId());
    return $entity_type->entityClassImplements(PurchasableEntityInterface::class) || $entity_type->entityClassImplements(OrderItemInterface::class);
  }

}
