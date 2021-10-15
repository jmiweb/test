<?php

namespace Drupal\schema_web_page\Plugin\metatag\Tag;

use Drupal\schema_metatag\Plugin\metatag\Tag\SchemaIdReferenceBase;

/**
 * Provides a plugin for the 'schema_web_page_work_translation' meta tag.
 *
 * - 'id' should be a globally unique id.
 * - 'name' should match the Schema.org element name.
 * - 'group' should match the id of the group that defines the Schema.org type.
 *
 * @MetatagTag(
 *   id = "schema_web_page_work_translation",
 *   label = @Translation("workTranslation"),
 *   description = @Translation("Translation(s) of this work"),
 *   name = "workTranslation",
 *   group = "schema_web_page",
 *   weight = 15,
 *   type = "string",
 *   secure = FALSE,
 *   multiple = TRUE
 * )
 */
class SchemaWebPageWorkTranslation extends SchemaIdReferenceBase {

}
