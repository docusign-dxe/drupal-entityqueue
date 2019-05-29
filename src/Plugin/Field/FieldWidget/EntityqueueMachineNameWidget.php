<?php

namespace Drupal\entityqueue\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'entityqueue_machine_name' field widget.
 *
 * @FieldWidget(
 *   id = "entityqueue_machine_name",
 *   label = @Translation("Machine name (Entityqueue)"),
 *   field_types = {
 *     "string"
 *   }
 * )
 */
class EntityqueueMachineNameWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface;
   */
  protected $entityFieldManager;

  /**
   * The element info manager.
   *
   * @var \Drupal\Core\Render\ElementInfoManagerInterface
   */
  protected $elementInfo;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityFieldManagerInterface $entity_field_manager, ElementInfoManagerInterface $element_info) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);

    $this->entityFieldManager = $entity_field_manager;
    $this->elementInfo = $element_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_field.manager'),
      $container->get('element_info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'source_field' => '',
      'disable_on_edit' => TRUE,
      'replace_pattern' => '[^a-z0-9_]+',
      'replace' => '_',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display */
    $form_display = $form_state->getFormObject()->getEntity();

    $available_fields = $this->entityFieldManager->getFieldDefinitions($form_display->getTargetEntityTypeId(), $form_display->getTargetBundle());
    $displayed_fields = $form_display->getComponents();

    $options = [];
    /**@var \Drupal\Core\Field\FieldDefinitionInterface $field */
    foreach (array_intersect_key($available_fields, $displayed_fields) as $field_name => $field) {
      // The source field can only be another string field.
      if ($field->getType() === 'string' && $field->getName() !== $this->fieldDefinition->getName()) {
        $options[$field_name] = $field->getLabel();
      }
    }
    $element['source_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Source field'),
      '#default_value' => $this->getSetting('source_field'),
      '#options' => $options,
      '#description' => $this->t('The field that should be used as a source for the machine name element. This field needs to be displayed in the entity form <em>before</em> the @field_label field.', ['@field_label' => $this->fieldDefinition->getLabel()]),
    ];
    $element['disable_on_edit'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable after initial creation'),
      '#default_value' => $this->getSetting('disable_on_edit'),
      '#description' => $this->t('Disable the machine name after the content has been saved for the first time.'),
    ];
    $element['replace_pattern'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Replace pattern'),
      '#default_value' => $this->getSetting('replace_pattern'),
      '#description' => $this->t('A regular expression (without delimiters) matching disallowed characters in the machine name.'),
      '#size' => 30,
    ];
    $element['replace'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Replace character'),
      '#default_value' => $this->getSetting('replace'),
      '#description' => $this->t("A character to replace disallowed characters in the machine name. When using a different character than '_', <em>Replace pattern</em> needs to be set accordingly."),
      '#size' => 1,
      '#maxlength' => 1,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $field_definitions = $this->entityFieldManager->getFieldDefinitions($this->fieldDefinition->getTargetEntityTypeId(), $this->fieldDefinition->getTargetBundle());
    if (!empty($this->getSetting('source_field')) && isset($field_definitions[$this->getSetting('source_field')])) {
      $summary[] = $this->t('Source field: @source_field', ['@source_field' => $field_definitions[$this->getSetting('source_field')]->getLabel()]);
      $summary[] = $this->t('Disable on edit: @disable_on_edit', ['@disable_on_edit' => $this->getSetting('disable_on_edit') ? $this->t('Yes') : $this->t('No')]);
      $summary[] = $this->t('Replace pattern: @replace_pattern', ['@replace_pattern' => $this->getSetting('replace_pattern')]);
      $summary[] = $this->t('Replace character: @replace', ['@replace' => $this->getSetting('replace')]);
    }
    else {
      $summary[] = $this->t('<em>Missing configuration</em>.');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element_info = $this->elementInfo->getInfo('machine_name');
    $element['value'] = $element + [
      '#type' => 'machine_name',
      '#default_value' => isset($items[$delta]->value) ? $items[$delta]->value : NULL,
      '#maxlength' => $this->getFieldSetting('max_length'),
      '#entity_type_id' => $this->fieldDefinition->getTargetEntityTypeId(),
      '#field_name' => $this->fieldDefinition->getName(),
      '#source_field' => $this->getSetting('source_field'),
      '#process' => array_merge([[get_class($this), 'processMachineNameSource']], $element_info['#process']),
      '#machine_name' => [
        // We don't need the default form-level validation because we enforce
        // the 'UniqueField' constraint on the field that uses this widget.
        'exists' => function () {
          return FALSE;
        },
        'label' => $this->fieldDefinition->getLabel(),
        'replace_pattern' => $this->getSetting('replace_pattern'),
        'replace' => $this->getSetting('replace'),
      ],
      '#disabled' => $this->getSetting('disable_on_edit') && !$items->getEntity()->isNew(),
    ];

    return $element;
  }

  /**
   * Form API callback: Sets the 'source' property of a machine_name element.
   *
   * This method is assigned as a #process callback in formElement() method.
   */
  public static function processMachineNameSource($element, FormStateInterface $form_state, $form) {
    $source_field_state = static::getWidgetState($element['#field_parents'], $element['#source_field'], $form_state);

    // Hide the field widget if the source field is not configured properly or
    // if it doesn't exist in the form.
    if (empty($element['#source_field']) || empty($source_field_state['array_parents'])) {
      $element['#access'] = FALSE;
    }
    else {
      $source_field_element = NestedArray::getValue($form_state->getCompleteForm(), $source_field_state['array_parents']);
      $element['#machine_name']['source'] = $source_field_element[$element['#delta']]['value']['#array_parents'];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    return $field_definition->getTargetEntityTypeId() === 'entity_subqueue' && $field_definition->getName() === 'name';
  }

}
