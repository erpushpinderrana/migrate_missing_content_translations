<?php

namespace Drupal\migrate_missing_content_translations\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Runs an array of arrays through its own process pipeline.
 *
 * First, I tried the OOTB sub_process plugin but it couldn't work in this scenario.
 * 
 * @MigrateProcessPlugin(
 *   id = "custom_nested_paragraph",
 *   handle_multiples = TRUE
 * )
 */
class CustomNestedParagraph extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $return = [];
    // The recommendation is to use the OOTB sub_process plugin.
    // Since it didn't work in this case so I developed this custom plugin.
    // After triaging what expected in the Nested Paragraph field in Article node
    // i.e. field_article_nested_items, it was easy to write this plugin.
    // The expected array was something like below:
    // array:2 [
    //   0 => array:2 [
    //     "target_id" => "32241"
    //     "target_revision_id" => "3031011"
    //   ]
    //   1 => array:2 [
    //     "target_id" => "32242"
    //     "target_revision_id" => "3031012"
    //   ]
    // ]
    // Hence, iterates all the paragraph items and get the source &
    // destination id. After iterating through all the paragraphs 
    // return the final nested paragraph array.
    
    $nested_paragraphs_items = [
      'migrate_nested_paragraphs_banner_content',
      'migrate_nested_paragraphs_hero_content',
    ];

    if (is_array($value)) {
      $connection = \Drupal::database();
      foreach ($value as $new_value) {
        foreach ($nested_paragraphs_items as $paragraphs_item) {
          $query = $connection->select('migrate_map_' . $paragraphs_item, 't');
          $query->fields('t', ['destid1', 'destid2']);
          $query->condition('t.sourceid1', $new_value['value']);
          $results = $query->execute()->fetchAll();
          foreach ($results as $field_name) {
            $return[] = [
              'target_id' => $field_name->destid1,
              'target_revision_id' => $field_name->destid2
            ];
          }
        }
      }
    }
    // dump(
    //   '---------------------------------------------------------------------',
    //   '|                      $custom_nested_paragraph_process_return       |',
    //   '---------------------------------------------------------------------',
    //   $return,
    //   '---------------------------------------------------------------------',
    //   '|                      $custom_nested_paragraph_process_return       |',
    // );
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function multiple() {
    return TRUE;
  }

}
