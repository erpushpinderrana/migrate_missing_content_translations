<?php

namespace Drupal\migrate_missing_content_translations\Plugin\migrate\source;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\d7\FieldableEntity;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Note: Just copied the Core Drupal 7 Node source plugin.
 * Question: Why did I not inherit this plugin instead of overriding?
 * The reason is I don't want to merge/return the parent::prepareRow($row);
 *
 * Drupal 7 node source from database.
 *
 * Available configuration keys:
 * - node_type: The node_types to get from the source - can be a string or
 *   an array. If not declared then nodes of all types will be retrieved.
 *
 * Examples:
 *
 * @code
 * source:
 *   plugin: d7_node
 *   node_type: page
 * @endcode
 *
 * In this example nodes of type page are retrieved from the source database.
 *
 * @code
 * source:
 *   plugin: d7_node
 *   node_type: [page, test]
 * @endcode
 *
 * In this example nodes of type page and test are retrieved from the source
 * database.
 *
 * For additional configuration keys, refer to the parent classes.
 *
 * @see \Drupal\migrate\Plugin\migrate\source\SqlBase
 * @see \Drupal\migrate\Plugin\migrate\source\SourcePluginBase
 *
 * @MigrateSource(
 *   id = "custom_node_translations",
 *   source_module = "node"
 * )
 */
class CustomNodeTranslations extends FieldableEntity {
  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, StateInterface $state, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state, $entity_type_manager);
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('module_handler')
    );
  }

  /**
   * The join options between the node and the node_revisions table.
   */
  const JOIN = '[n].[vid] = [nr].[vid]';

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Select node in its last revision.
    $query = $this->select('node_revision', 'nr')
      ->fields('n', [
        'nid',
        'type',
        'language',
        'status',
        'created',
        'changed',
        'comment',
        'promote',
        'sticky',
        'tnid',
        'translate',
      ])
      ->fields('nr', [
        'vid',
        'title',
        'log',
        'timestamp',
      ]);
    $query->addField('n', 'uid', 'node_uid');
    $query->addField('nr', 'uid', 'revision_uid');
    $query->innerJoin('node', 'n', static::JOIN);

    // If the content_translation module is enabled, get the source langcode
    // to fill the content_translation_source field.
    if ($this->moduleHandler->moduleExists('content_translation')) {
      $query->leftJoin('node', 'nt', '[n].[tnid] = [nt].[nid]');
      $query->addField('nt', 'language', 'source_langcode');
    }
    $this->handleTranslations($query);

    if (isset($this->configuration['node_type'])) {
      $query->condition('n.type', (array) $this->configuration['node_type'], 'IN');
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $nid = $row->getSourceProperty('nid');
    $vid = $row->getSourceProperty('vid');
    $type = $row->getSourceProperty('type');
    $langcode = $row->getSourceProperty('language');

    // If this entity was translated using Entity Translation, we need to get
    // its source language to get the field values in the right language.
    // The translations will be migrated by the d7_node_entity_translation
    // migration.
    $entity_translatable = $this->isEntityTranslatable('node') && (int) $this->variableGet('language_content_type_' . $type, 0) === 4;
    $source_language = $this->getEntityTranslationSourceLanguage('node', $nid);
    $language = $entity_translatable && $source_language ? $source_language : $row->getSourceProperty('language');

    // If this is using d7_node_complete source plugin and this is a node
    // using entity translation then set the language of this revision to the
    // entity translation language.
    if ($row->getSourceProperty('etr_created')) {
      $language = $row->getSourceProperty('language');
    }

    // Get Field API field values.
    foreach ($this->getFields('node', $type) as $field_name => $field) {
      // Ensure we're using the right language if the entity and the field are
      // translatable.
      $field_language = $entity_translatable && $field['translatable'] ? $language : NULL;
      $row->setSourceProperty($field_name, $this->getFieldValues('node', $field_name, $nid, $vid, $field_language));
    }

    // Make sure we always have a translation set.
    if ($row->getSourceProperty('tnid') == 0) {
      $row->setSourceProperty('tnid', $row->getSourceProperty('nid'));
    }

    // If the node title was replaced by a real field using the Drupal 7 Title
    // module, use the field value instead of the node title.
    if ($this->moduleExists('title')) {
      $title_field = $row->getSourceProperty('title_field');
      if (isset($title_field[0]['value'])) {
        $row->setSourceProperty('title', $title_field[0]['value']);
      }
    }

    // Include path alias.
    $query = $this->select('url_alias', 'ua')
      ->fields('ua', ['alias']);
    $query->condition('ua.source', 'node/' . $nid);
    $alias = $query->execute()->fetchField();
    if (!empty($alias)) {
      $row->setSourceProperty('alias', '/' . $alias);
    }

//     dump(
//       '---------------------------------------------------------------------',
//       '|                             $row                               |',
//       '---------------------------------------------------------------------',
//       $row,
//       '---------------------------------------------------------------------',
//       '|                           $row                            |',
//       '---------------------------------------------------------------------'
//     );
    
    // To triage 1:1 mapping between source and destination, we may add debugging info.
    // Add a text field (i.e. field_debug) with Unlimited Allowed number of values in the Article content type. 
//     $debug = [
//       0 => 'Source Nid: ' . $nid,
//       1 => 'D7 Content Type: ' . $row->getSourceProperty('type'),
//       2 => 'Node Language: ' . $langcode,
//       3 => 'Node Status: ' . $row->getSourceProperty('status'),
//     ];

    // Store debug info.
//     $row->setSourceProperty('field_debug', $debug);
    
    return $row;
    //return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'nid' => $this->t('Node ID'),
      'type' => $this->t('Type'),
      'title' => $this->t('Title'),
      'node_uid' => $this->t('Node authored by (uid)'),
      'revision_uid' => $this->t('Revision authored by (uid)'),
      'created' => $this->t('Created timestamp'),
      'changed' => $this->t('Modified timestamp'),
      'status' => $this->t('Published'),
      'promote' => $this->t('Promoted to front page'),
      'sticky' => $this->t('Sticky at top of lists'),
      'revision' => $this->t('Create new revision'),
      'language' => $this->t('Language (fr, en, ...)'),
      'tnid' => $this->t('The translation set id for this node'),
      'timestamp' => $this->t('The timestamp the latest revision of this node was created.'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['nid']['type'] = 'integer';
    $ids['nid']['alias'] = 'n';
    return $ids;
  }

  /**
   * Adapt our query for translations.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The generated query.
   */
  protected function handleTranslations(SelectInterface $query) {
    // Translations: We only need translations so putting an extra check here.
    $query->where('[n].[tnid] <> 0 AND [n].[tnid] <> [n].[nid]');
    $query->condition('n.language', 'en', '!=');
    //$query->range(0, 5);
  }

}
