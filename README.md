# Migrate Missing Content Translations
It's a custom module/approach that was developed after the base/initial migration was complete. What it means is while upgrading the website from D7 to D8 the whole content was migrated except translations. There are tons of documents available on the Drupal.org and other forums which describe how to levergae core migration modules to perform the migration from D7 to D9. Though most of the time, there is a need of custom development. The idea of this module is to provide more context detail to undertstand how can we levergae/extend OOTB migration modules. 

# Problem Statement
Migrate content translations from D7 to D9 when the base migration modules are not available. However, the mapping tables are still there. The ask is to use these mapping tables and based in the tnid in D7, only migrate the translations.

# Solution
Let's levergae a few of OOTB modules as listed below.
1. [Migrate Tools](https://www.drupal.org/project/migrate_tools/)
2. [Migrate Plus](https://www.drupal.org/project/migrate_plus/)
3. [Migrate Devel](https://www.drupal.org/project/migrate_devel) - It's not a mandatory module and not required on production. It's really helpful during the development.
4. Create a custom module for the migration i.e. migrate_missing_content_translations

# Custom Module
Since the existing base/source migration plugins are no longer available hence we can't use the OOTB source and MigrationLookup plugins. Create a custom source and MigrationLookup plugin. Also, there is a need to migrating Field collection from D7 so we also need to write a source plugin for getting the Field Collection items from D7. I've added detailed comment in the each config file to understand how it works.

## Migrate D7 Field Collection items to D9 Nested Paragraphs.
It's a two step process. First we need to migrate the individual paragraph and once it's complete then link these paragraphs with the correct node.

Here's the structure of D9 schema.
![Article Nested Paragraph in Node](https://github.com/erpushpinderrana/files/blob/master/ARTICLE%20NESTED%20ITEMS%20-%20Article%20Node%20FIeld%20.png)
![Article Nested Paragraph Storage](https://github.com/erpushpinderrana/files/blob/master/ARTICLE%20NESTED%20ITEMS%20Field%20Storage.png)
![Article Nested Paragraph Field](https://github.com/erpushpinderrana/files/blob/master/ARTICLE%20NESTED%20ITEMS%20Field.png)
![Nested Paragraph - Banner Paragraph](https://github.com/erpushpinderrana/files/blob/master/Article%20FC%20Items%20Banner%20Content.png)
![Nested Paragraph - Hero Paragraph](https://github.com/erpushpinderrana/files/blob/master/Article%20FC%20Items%20Hero%20Content.png)

## Migrate Import Commands.
First migrate the sub child paragraphs and once it's complete then migrate the article node migration.

**Migrate Hero Content Paragraph**
```
drush migrate-import migrate_nested_paragraphs_hero_content
```

If we have enabled Migrate devel locally, then we can use the below command (adding `--migrate-debug`) to see the each row in the drush terminal.
```
drush migrate-import migrate_nested_paragraphs_hero_content --migrate-debug
```
**Rollback Hero Content Paragraph**
In case, we want to rollback the migrated paragraphs hero content then run the below command.
```
drush migrate-rollback migrate_nested_paragraphs_hero_content
```
**Reset Hero Content Paragraph**
In case, if there is any error then we may need to reset the migration. Here's the command for that.
```
drush migrate-reset migrate_nested_paragraphs_hero_content
```

**Run Two Other Migrations**
The same way, we need to run the `migrate_nested_paragraphs_banner_content` migration.
```
drush migrate-import migrate_nested_paragraphs_banner_content
```
Finally, the article migration.
```
drush migrate-import migrate_article_translations
```
